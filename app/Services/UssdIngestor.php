<?php

namespace App\Services;

use App\Enums\ResultStatus;
use App\Models\Agent;
use App\Models\Incident;
use App\Models\MaterialReport;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\Result;
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
        $unit = $this->pollingUnit($data['polling_unit'] ?? []);
        $incident = Incident::firstOrNew(['reference' => $this->required($data, 'reference')]);

        $incident->fill([
            'polling_unit_code' => $unit['code'],
            'lga' => $unit['lga'] ?? $incident->lga,
            'ward' => $unit['ward'] ?? $incident->ward,
            'type' => $this->required($data, 'type'),
            'type_label' => $data['type_label'] ?? null,
            'urgent' => (bool) ($data['urgent'] ?? false),
            'note' => $data['note'] ?? null,
            'agent_name' => Arr::get($data, 'agent.name'),
            'agent_phone' => Arr::get($data, 'agent.phone_number'),
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

        $presence->fill([
            'polling_unit_code' => $unit['code'],
            'lga' => $unit['lga'] ?? $presence->lga,
            'ward' => $unit['ward'] ?? $presence->ward,
            'agent_name' => Arr::get($data, 'agent.name'),
            'agent_phone' => Arr::get($data, 'agent.phone_number'),
            'confirmed_at' => Time::parse($data['confirmed_at'] ?? null),
            'rehearsal' => $rehearsal ?? ($presence->exists ? $presence->rehearsal : false),
        ])->save();

        return $presence;
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
