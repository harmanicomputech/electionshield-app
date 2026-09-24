<?php

namespace App\Services\Broadcasting;

use App\Models\Agent;
use App\Models\Contact;
use App\Models\OptOut;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who a broadcast goes to. Consent rules:
 *  - supporters and "other" contacts only if they opted in to the channel;
 *  - coordinators and agents get operational SMS without a separate opt-in;
 *  - WhatsApp always needs a WhatsApp opt-in (Meta's rule), so agents,
 *    who have none here, never get WhatsApp broadcasts;
 *  - anyone who opted out of the channel (STOP) is always left out.
 *
 * Audience: {"groups": ["supporters", "agents", "coordinators", "other"],
 *            "lgas": ["Abakaliki"], "wards": ["Abakaliki|Ward 01"]}
 */
class Audience
{
    public const GROUPS = [
        'supporters' => 'Supporters (opted in)',
        'agents' => 'Polling agents',
        'coordinators' => 'Coordinators',
        'other' => 'Other contacts (opted in)',
    ];

    /**
     * @param  array{groups?: list<string>, lgas?: list<string>, wards?: list<string>}  $audience
     * @return Collection<string, array{phone: string, name: ?string}> by phone
     */
    public function recipients(array $audience, string $channel): Collection
    {
        $groups = $audience['groups'] ?? [];
        $lgas = array_values(array_filter($audience['lgas'] ?? []));
        $wards = collect($audience['wards'] ?? [])->filter()->map(fn (string $ward) => explode('|', $ward, 2))->filter(fn ($pair) => count($pair) === 2)->values();
        $optIn = $channel === 'whatsapp' ? 'whatsapp_opt_in_at' : 'sms_opt_in_at';

        $area = function (Builder $query, string $lgaColumn, string $wardColumn) use ($lgas, $wards) {
            $query->when($lgas !== [] || $wards->isNotEmpty(), fn (Builder $query) => $query->where(function (Builder $query) use ($lgas, $wards, $lgaColumn, $wardColumn) {
                $query->when($lgas !== [], fn (Builder $query) => $query->orWhereIn($lgaColumn, $lgas));
                foreach ($wards as [$lga, $ward]) {
                    $query->orWhere(fn (Builder $query) => $query->where($lgaColumn, $lga)->where($wardColumn, $ward));
                }
            }));
        };

        $people = collect();

        $types = array_values(array_intersect_key(['supporters' => 'supporter', 'coordinators' => 'coordinator', 'other' => 'other'], array_flip($groups)));

        if ($types !== []) {
            Contact::query()
                ->whereIn('type', $types)
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query->whereIn('type', ['supporter', 'other'])->whereNotNull($optIn))
                    ->orWhere(fn (Builder $query) => $query->where('type', 'coordinator')->when($channel === 'whatsapp', fn (Builder $query) => $query->whereNotNull($optIn))))
                ->tap(fn (Builder $query) => $area($query, 'lga', 'ward'))
                ->orderBy('id')
                ->each(fn (Contact $contact) => $people->push(['phone' => $contact->phone, 'name' => $contact->name]));
        }

        if (in_array('agents', $groups, true) && $channel === 'sms') {
            Agent::query()
                ->leftJoin('polling_units', 'polling_units.code', '=', 'agents.polling_unit_code')
                ->tap(fn (Builder $query) => $area($query, 'polling_units.lga', 'polling_units.ward'))
                ->orderBy('agents.id')
                ->get(['agents.name', 'agents.phone_number'])
                ->each(fn (Agent $agent) => $people->push(['phone' => Phone::normalize($agent->phone_number), 'name' => $agent->name]));
        }

        $optedOut = OptOut::query()->where('channel', $channel)->pluck('phone')->flip();

        return $people
            ->filter(fn (array $person) => $person['phone'] !== null && ! $optedOut->has($person['phone']))
            ->unique('phone')
            ->keyBy('phone');
    }
}
