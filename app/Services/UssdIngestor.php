<?php

namespace App\Services;

use App\Enums\ResultStatus;
use App\Models\Agent;
use App\Models\Incident;
use App\Models\MaterialReport;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\Result;
use App\Models\Volunteer;
use App\Support\Time;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applies USSD records to our copy, whether they come from a webhook event or
 * the read API. Every write is an upsert by reference or id, so the same
 * record can arrive any number of times and in any order.
 */
class UssdIngestor
{
    public const EVENTS = [
        'result.submitted',
        'result.correction_requested',
        'result.corrected',
        'result.correction_rejected',
        'incident.reported',
        'materials.reported',
        'presence.confirmed',
        'volunteer.registered',
    ];

    /**
     * Apply one webhook event. Returns false for an event type we don't know.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyEvent(string $event, array $data): bool
    {
        $rehearsal = (bool) ($data['rehearsal'] ?? false);

        match ($event) {
            'result.submitted', 'result.correction_requested', 'result.corrected', 'result.correction_rejected' => $this->result($data, $rehearsal, $data['superseded_reference'] ?? null),
            'incident.reported' => $this->incident($data, $rehearsal),
            'materials.reported' => $this->materials($data, $rehearsal),
            'presence.confirmed' => $this->presence($data, $rehearsal),
            'volunteer.registered' => $this->volunteer($data, $rehearsal),
            default => null,
        };

        return in_array($event, self::EVENTS, true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function result(array $data, ?bool $rehearsal = null, ?string $supersededReference = null): Result
    {
        $reference = $this->required($data, 'reference');
        $incoming = ResultStatus::from($this->required($data, 'status'));
        $unit = $this->pollingUnit($data['polling_unit'] ?? []);

        return DB::transaction(function () use ($data, $reference, $incoming, $unit, $rehearsal, $supersededReference) {
            $result = Result::query()->where('reference', $reference)->lockForUpdate()->first() ?? new Result(['reference' => $reference]);

            // Never move a result back to an earlier stage (a late
            // result.submitted must not undo its supersession).
            $status = $result->exists && $result->status->stage() > $incoming->stage() ? $result->status : $incoming;

            // The approved correction of this result may have arrived first.
            if ($status === ResultStatus::Accepted && Result::query()->where('corrects_reference', $reference)->where('status', ResultStatus::Accepted)->exists()) {
                $status = ResultStatus::Superseded;
            }

            $result->fill([
                'status' => $status,
                'polling_unit_code' => $unit['code'],
                'lga' => $unit['lga'] ?? $result->lga,
                'ward' => $unit['ward'] ?? $result->ward,
                'accredited_voters' => (int) ($data['accredited_voters'] ?? 0),
                'total_valid_votes' => (int) ($data['total_valid_votes'] ?? array_sum($data['votes'] ?? [])),
                'rejected_votes' => (int) ($data['rejected_votes'] ?? 0),
                'total_votes_cast' => (int) ($data['total_votes_cast'] ?? 0),
                'corrects_reference' => $data['corrects_reference'] ?? $result->corrects_reference,
                'agent_name' => Arr::get($data, 'agent.name'),
                'agent_phone' => Arr::get($data, 'agent.phone_number'),
                'channel' => Arr::get($data, 'channel') === 'web' ? 'web' : 'ussd',
                'submitted_at' => Time::parse($data['submitted_at'] ?? null),
                'reviewed_at' => Time::parse($data['reviewed_at'] ?? null) ?? $result->reviewed_at,
                'reviewed_by' => $data['reviewed_by'] ?? $result->reviewed_by,
                'review_note' => $data['review_note'] ?? $result->review_note,
                'rehearsal' => $rehearsal ?? ($result->exists ? $result->rehearsal : false),
            ])->save();

            foreach ((array) ($data['votes'] ?? []) as $party => $votes) {
                $result->votes()->updateOrCreate(['party' => (string) $party], ['votes' => (int) $votes]);
            }
            $result->votes()->whereNotIn('party', array_map('strval', array_keys((array) ($data['votes'] ?? []))))->delete();

            // An accepted correction replaces the result it corrects.
            if ($status === ResultStatus::Accepted) {
                $replaced = array_filter(array_unique([$supersededReference, $result->corrects_reference]));

                if ($replaced !== []) {
                    Result::query()
                        ->whereIn('reference', $replaced)
                        ->where('reference', '!=', $reference)
                        ->where('status', ResultStatus::Accepted)
                        ->update(['status' => ResultStatus::Superseded, 'updated_at' => now()]);
                }
            }

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function incident(array $data, ?bool $rehearsal = null): Incident
    {
        $public = ($data['source'] ?? null) === Incident::SOURCE_PUBLIC;
        // A member of the public may give only an LGA and ward (no PU code).
        $unit = $public && blank($data['polling_unit']['code'] ?? null)
            ? ['code' => null, ...array_filter(Arr::only((array) ($data['polling_unit'] ?? []), ['lga', 'ward']))]
            : $this->pollingUnit($data['polling_unit'] ?? []);
        $incident = Incident::firstOrNew(['reference' => $this->required($data, 'reference')]);

        $incident->fill([
            'polling_unit_code' => $unit['code'],
            'lga' => $unit['lga'] ?? $incident->lga,
            'ward' => $unit['ward'] ?? $incident->ward,
            'source' => $public ? Incident::SOURCE_PUBLIC : Incident::SOURCE_AGENT,
            'reporter_phone' => $public ? ($data['reporter_phone'] ?? null) : null,
            'type' => $this->required($data, 'type'),
            'type_label' => $data['type_label'] ?? null,
            'urgent' => (bool) ($data['urgent'] ?? false),
            'note' => $data['note'] ?? null,
            'agent_name' => Arr::get($data, 'agent.name'),
            'agent_phone' => Arr::get($data, 'agent.phone_number'),
            'channel' => Arr::get($data, 'channel') === 'web' ? 'web' : 'ussd',
            'reported_at' => Time::parse($data['reported_at'] ?? null),
            'rehearsal' => $rehearsal ?? ($incident->exists ? $incident->rehearsal : false),
        ])->save();

        return $incident;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function materials(array $data, ?bool $rehearsal = null): MaterialReport
    {
        $unit = $this->pollingUnit($data['polling_unit'] ?? []);
        $report = MaterialReport::firstOrNew(['ussd_id' => (int) $this->required($data, 'id')]);

        $report->fill([
            'polling_unit_code' => $unit['code'],
            'lga' => $unit['lga'] ?? $report->lga,
            'ward' => $unit['ward'] ?? $report->ward,
            'status' => $this->required($data, 'status'),
            'status_label' => $data['status_label'] ?? null,
            'agent_name' => Arr::get($data, 'agent.name'),
            'agent_phone' => Arr::get($data, 'agent.phone_number'),
            'channel' => Arr::get($data, 'channel') === 'web' ? 'web' : 'ussd',
            'reported_at' => Time::parse($data['reported_at'] ?? null),
            'rehearsal' => $rehearsal ?? ($report->exists ? $report->rehearsal : false),
        ])->save();

        return $report;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function presence(array $data, ?bool $rehearsal = null): Presence
    {
        $unit = $this->pollingUnit($data['polling_unit'] ?? []);
        $presence = Presence::firstOrNew(['ussd_id' => (int) $this->required($data, 'id')]);

        $confirmedAt = Time::parse($data['confirmed_at'] ?? null);

        // Checked in again: the position of the earlier check-in no longer applies
        // (a web check-in gets its new position straight after this).
        if ($presence->exists && $presence->confirmed_at?->getTimestamp() !== $confirmedAt?->getTimestamp()) {
            $presence->forceFill(['latitude' => null, 'longitude' => null, 'location_accuracy' => null, 'located_at' => null, 'location_reviewed_at' => null, 'location_reviewed_by' => null]);
        }

        $presence->fill([
            'polling_unit_code' => $unit['code'],
            'lga' => $unit['lga'] ?? $presence->lga,
            'ward' => $unit['ward'] ?? $presence->ward,
            'agent_name' => Arr::get($data, 'agent.name'),
            'agent_phone' => Arr::get($data, 'agent.phone_number'),
            'channel' => Arr::get($data, 'channel') === 'web' ? 'web' : 'ussd',
            'confirmed_at' => $confirmedAt,
            'rehearsal' => $rehearsal ?? ($presence->exists ? $presence->rehearsal : false),
        ])->save();

        return $presence;
    }

    /**
     * A "How can you help?" sign-up. An older version arriving late never
     * overwrites a newer one; our own follow-up notes are never touched.
     *
     * @param  array<string, mixed>  $data
     */
    public function volunteer(array $data, ?bool $rehearsal = null): Volunteer
    {
        $volunteer = Volunteer::firstOrNew(['reference' => $this->required($data, 'reference')]);
        $updatedAt = Time::parse($data['updated_at'] ?? null);

        if ($volunteer->exists && $volunteer->ussd_updated_at && $updatedAt && $updatedAt->lt($volunteer->ussd_updated_at)) {
            return $volunteer;
        }

        $volunteer->fill([
            'name' => (string) ($data['name'] ?? ''),
            'phone_number' => (string) ($data['phone_number'] ?? ''),
            'contact_phone' => (string) ($data['contact_phone'] ?? $data['phone_number'] ?? ''),
            'lga' => $data['lga'] ?? null,
            'ward' => $data['ward'] ?? null,
            'roles' => array_values(array_filter((array) ($data['roles'] ?? []), 'is_string')),
            'skills' => array_values(array_filter((array) ($data['skills'] ?? []), 'is_string')) ?: null,
            'other' => $data['other'] ?? null,
            'is_agent' => (bool) ($data['is_agent'] ?? false),
            'channel' => Arr::get($data, 'channel') === 'web' ? 'web' : 'ussd',
            'registered_at' => Time::parse($data['registered_at'] ?? null) ?? $volunteer->registered_at,
            'ussd_updated_at' => $updatedAt,
            'rehearsal' => $rehearsal ?? ($volunteer->exists ? $volunteer->rehearsal : false),
        ])->save();

        return $volunteer;
    }

    /**
     * Upsert a PU from the register or from an event's `polling_unit`, and
     * return its fields. A record carrying only the code gets its LGA and
     * ward from the register.
     *
     * @param  array<string, mixed>  $data
     * @return array{code: string, name?: string, ward?: string, lga?: string, registered_voters?: int}
     */
    public function pollingUnit(array $data): array
    {
        $code = PollingUnit::normalizeCode((string) ($data['code'] ?? ''));

        if ($code === '') {
            throw new InvalidArgumentException('The record has no polling_unit.code.');
        }

        $fields = array_filter(Arr::only($data, ['name', 'ward', 'lga', 'registered_voters']), fn ($value) => $value !== null);

        if ($fields !== []) {
            PollingUnit::updateOrCreate(['code' => $code], $fields);
        } else {
            // Only the code: take the LGA and ward from the register.
            $fields = array_filter(PollingUnit::query()->where('code', $code)->first(['lga', 'ward'])?->only(['lga', 'ward']) ?? []);
        }

        return ['code' => $code, ...$fields];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function agent(array $data): Agent
    {
        $unit = isset($data['polling_unit']['code']) ? $this->pollingUnit($data['polling_unit']) : null;

        return Agent::updateOrCreate(['ussd_id' => (int) $this->required($data, 'id')], [
            'name' => (string) ($data['name'] ?? ''),
            'phone_number' => (string) ($data['phone_number'] ?? ''),
            'polling_unit_code' => $unit['code'] ?? null,
            'locked' => (bool) ($data['locked'] ?? false),
            'last_seen_at' => Time::parse($data['last_seen_at'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function required(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (blank($value) || ! is_scalar($value)) {
            throw new InvalidArgumentException("The record has no {$key}.");
        }

        return (string) $value;
    }
}
