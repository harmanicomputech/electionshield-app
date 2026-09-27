<?php

namespace App\Services;

use App\Enums\ResultStatus;
use App\Models\AlertSnooze;
use App\Models\Attachment;
use App\Models\Ec8aPhoto;
use App\Models\Incident;
use App\Models\PollingUnit;
use App\Models\Result;
use App\Models\User;
use App\Support\Permission;
use App\Support\Settings;
use App\Support\Time;
use Illuminate\Support\Collection;

/**
 * What pops up in the situation room: recent incidents nobody has
 * acknowledged, and recent results (and corrections) nobody has
 * acknowledged, for the person's home LGA (or everywhere) and their
 * permissions, minus the ones they asked to be reminded about later.
 * Dismissing a pop-up snoozes it for a few minutes, so an unanswered report
 * keeps coming back.
 */
class AlertFeed
{
    private const LIMIT = 20;

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(User $user): array
    {
        $since = now()->subHours((int) config('election.alerts.recent_hours'));
        $rehearsal = Settings::showingRehearsal();
        $snoozed = AlertSnooze::query()->where('user_id', $user->id)->where('until', '>', now())->pluck('reference')->all();
        $phones = $user->can(Permission::VIEW_AGENTS);
        $items = collect();

        if ($user->can(Permission::RESPOND_INCIDENTS)) {
            $incidents = Incident::query()
                ->where('rehearsal', $rehearsal)
                ->withResponseStatus(Incident::OPEN)
                ->where('reported_at', '>=', $since)
                ->whereNotIn('reference', $snoozed)
                ->when($user->lga, fn ($query, $lga) => $query->where('lga', $lga))
                ->orderByDesc('urgent')->latest('reported_at')->limit(self::LIMIT)->get();

            $items = $items->merge($incidents->map(fn (Incident $incident) => $this->incident($incident, $phones)));
        }

        if ($user->can(Permission::ACKNOWLEDGE_RESULTS)) {
            $results = Result::query()->with('votes')
                ->where('rehearsal', $rehearsal)
                ->whereNull('acknowledged_at')
                ->whereIn('status', [ResultStatus::Accepted, ResultStatus::Pending])
                ->where('submitted_at', '>=', $since)
                ->whereNotIn('reference', $snoozed)
                ->when($user->lga, fn ($query, $lga) => $query->where('lga', $lga))
                ->latest('submitted_at')->limit(self::LIMIT)->get();

            $items = $items->merge($results->map(fn (Result $result) => $this->result($result, $phones, $user)));
        }

        $this->withPlaces($items);

        return $items->sortBy([['priority', 'asc'], ['time', 'desc']])->values()->take(self::LIMIT)->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function incident(Incident $incident, bool $phones): array
    {
        return [
            'kind' => 'incident',
            'reference' => $incident->reference,
            'priority' => $incident->urgent ? 0 : 2,
            'urgent' => (bool) $incident->urgent,
            'title' => ($incident->urgent ? '⚠ ' : '').$incident->label(),
            'heading' => $incident->urgent ? 'Urgent incident' : 'New incident',
            'note' => $incident->note,
            'code' => $incident->polling_unit_code,
            'lga' => $incident->lga,
            'ward' => $incident->ward,
            'channel' => $incident->channel === 'web' ? 'Web app' : 'USSD',
            'agent' => $incident->agent_name,
            'phone' => $phones ? $incident->agent_phone : null,
            'time' => $incident->reported_at?->toIso8601String(),
            'when' => $incident->reported_at ? Time::local($incident->reported_at, 'g:i A') : null,
            'media' => $this->mediaCount($incident->reference),
            'open' => route('incidents', ['status' => 'unresolved']).'#incident-'.$incident->reference,
            'acknowledge' => route('incidents.acknowledge', $incident->reference),
            'resolve' => route('incidents.resolve', $incident->reference),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Result $result, bool $phones, User $user): array
    {
        $correction = $result->corrects_reference !== null;

        return [
            'kind' => 'result',
            'reference' => $result->reference,
            'priority' => $correction ? 1 : 3,
            'urgent' => false,
            'title' => $correction ? "Correction to {$result->corrects_reference}" : 'Result submitted',
            'heading' => $correction ? 'Correction waiting for review' : 'New result',
            'votes' => $this->ballotOrder($result->votes->pluck('votes', 'party')->map(fn ($votes) => (int) $votes)->all()),
            'accredited' => $result->accredited_voters,
            'rejected' => $result->rejected_votes,
            'code' => $result->polling_unit_code,
            'lga' => $result->lga,
            'ward' => $result->ward,
            'channel' => $result->channel === 'web' ? 'Web app' : 'USSD',
            'agent' => $result->agent_name,
            'phone' => $phones ? $result->agent_phone : null,
            'time' => $result->submitted_at?->toIso8601String(),
            'when' => $result->submitted_at ? Time::local($result->submitted_at, 'g:i A') : null,
            'media' => $this->mediaCount($result->reference),
            'open' => $correction ? route('corrections') : ($result->lga && $result->ward ? route('collation.ward', [$result->lga, $result->ward]) : route('collation')),
            'acknowledge' => route('results.acknowledge', $result->reference),
            'review' => $correction && $user->can(Permission::REVIEW_CORRECTIONS) ? route('corrections') : null,
        ];
    }

    /**
     * JSON objects keep key order, so the pop-up lists parties as on the ballot.
     *
     * @param  array<string, int>  $votes
     * @return array<string, int>
     */
    private function ballotOrder(array $votes): array
    {
        $order = array_flip(Collation::parties());
        uksort($votes, fn (string $a, string $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX) ?: strcmp($a, $b));

        return $votes;
    }

    private function mediaCount(string $reference): int
    {
        return Attachment::query()->where('reference', $reference)->count() + Ec8aPhoto::query()->where('result_reference', $reference)->count();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function withPlaces(Collection $items): void
    {
        $names = PollingUnit::query()->whereIn('code', $items->pluck('code')->unique())->pluck('name', 'code');

        $items->transform(fn (array $item) => [...$item, 'place' => $names[$item['code']] ?? 'PU '.$item['code']]);
    }
}
