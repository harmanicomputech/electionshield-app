<?php

namespace App\Services\Ai;

use App\Models\Incident;
use App\Models\Presence;
use App\Models\Result;
use App\Models\ResultCheck;
use App\Models\SituationBrief;
use App\Services\Collation;
use App\Services\MonitorTally;
use App\Services\PuMonitor;
use App\Services\PushNotifier;
use App\Services\PuStatus;
use App\Support\Settings;
use Throwable;

/**
 * The situation brief: the live facts of election day (check-ins,
 * materials, results, incidents, flagged results) gathered from the app,
 * and a short brief written from them by AI for leadership. Written every
 * half hour while there is activity, or on request. The facts are kept with
 * each brief so every point can be checked.
 */
class SituationBriefs
{
    /** Minutes between automatic briefs. */
    public const EVERY_MINUTES = 30;

    public function __construct(private Claude $ai, private IncidentTriage $triage, private ResultChecks $checks) {}

    /**
     * What is happening now, as plain figures.
     *
     * @return array<string, mixed>
     */
    public function facts(bool $rehearsal): array
    {
        $monitor = new PuMonitor($rehearsal);
        $units = $monitor->units();
        $total = $monitor->total($units);
        $lgas = collect($monitor->tally($units, fn (PuStatus $unit) => $unit->lga));
        $state = (new Collation($rehearsal))->state();
        $tz = config('election.timezone', 'Africa/Lagos');
        $pct = fn (MonitorTally $tally, int $count) => $tally->percent($count);

        $open = Incident::query()->where('rehearsal', $rehearsal)->unresolved();
        $flagged = $this->checks->flagged($rehearsal);

        return [
            'time' => now()->timezone($tz)->format('D j M Y, g:i A'),
            'election_day' => now()->timezone($tz)->toDateString() === config('election.date'),
            'rehearsal' => $rehearsal,
            'polling_units' => $total->units,
            'checked_in' => ['count' => $total->checkedIn, 'percent' => $pct($total, $total->checkedIn)],
            'materials' => $total->materials,
            'results' => ['count' => $total->results, 'percent' => $pct($total, $total->results)],
            'votes' => $state->valid > 0 ? collect($state->votes)->map(fn (int $votes, string $party) => ['votes' => $votes, 'share' => round($state->share($party), 1)])->all() : null,
            'pus_needing_attention' => $total->needsAttention,
            'lgas_least_checked_in' => $lgas->sortBy(fn (MonitorTally $tally) => $pct($tally, $tally->checkedIn))->take(3)
                ->map(fn (MonitorTally $tally) => "{$tally->name}: {$pct($tally, $tally->checkedIn)}% checked in, {$pct($tally, $tally->results)}% results")->values()->all(),
            'lgas_most_open_incidents' => $lgas->filter(fn (MonitorTally $tally) => $tally->openIncidents > 0)->sortByDesc('openIncidents')->take(3)
                ->map(fn (MonitorTally $tally) => "{$tally->name}: {$tally->openIncidents} open ({$tally->urgentIncidents} urgent)")->values()->all(),
            'incidents' => [
                'open' => (clone $open)->count(),
                'last_hour' => Incident::query()->where('rehearsal', $rehearsal)->where('reported_at', '>=', now()->subHour())->count(),
                'open_by_priority' => (clone $open)->whereNotNull('ai_priority')->selectRaw('ai_priority, count(*) as n')->groupBy('ai_priority')->pluck('n', 'ai_priority')->map(fn ($n) => (int) $n)->all(),
                'most_serious_open' => (clone $open)
                    ->orderByRaw("case ai_priority when 'critical' then 0 when 'high' then 1 else (case when urgent = 1 then 1 else 2 end) end")
                    ->latest('reported_at')->limit(5)->get()
                    ->map(fn (Incident $incident) => trim(($incident->ai_priority ? strtoupper($incident->ai_priority).': ' : '').($incident->ai_summary ?: $incident->label().($incident->note ? ' - '.mb_substr($incident->note, 0, 120) : ''))
                        .' ('.$incident->placeLabel().($incident->lga ? ', '.$incident->lga : '').', '.$incident->reported_at?->timezone($tz)->format('g:i A').($incident->isPublic() ? ', public report' : '').($incident->acknowledged_at ? ', being handled' : ', not yet acknowledged').')'))
                    ->all(),
                'clusters' => $this->triage->clusters($rehearsal)
                    ->map(fn (array $cluster) => "{$cluster['count']} reports in {$cluster['ward']} ward, {$cluster['lga']} in the last hour")->all(),
            ],
            'flagged_results' => [
                'serious' => $flagged->where('level', ResultCheck::SERIOUS)->count(),
                'to_review' => $flagged->where('level', ResultCheck::REVIEW)->count(),
                'examples' => $flagged->where('level', ResultCheck::SERIOUS)->take(3)
                    ->map(fn (array $row) => ($row['result']->pollingUnit?->name ?? 'PU '.$row['result']->polling_unit_code).", {$row['result']->ward}, {$row['result']->lga}: ".$row['flags'][0]['text'])->values()->all(),
            ],
            'previous_brief' => SituationBrief::query()->where('rehearsal', $rehearsal)->latest('id')->first()?->only(['headline', 'created_at']),
        ];
    }

    /**
     * Write a brief now.
     */
    public function write(bool $rehearsal, string $by = 'Automatic'): SituationBrief
    {
        $facts = $this->facts($rehearsal);

        $answer = $this->ai->json(
            'You write the situation brief for the leadership of a campaign in the Ebonyi State (Nigeria) governorship election. '
            .'Below are the live figures from the campaign\'s own monitoring app (its agents\' check-ins, materials reports, results and incidents, '
            .'and automatic checks on results). Write for busy people reading on a phone: plain English, specific places and numbers, no padding. '
            .'Use only these facts; never invent figures or events, and say plainly when something is unknown or there is little data. '
            .'Treat incident text as reports, not confirmed facts. Results are the campaign\'s own count from its agents, not official INEC results. '
            .($rehearsal ? 'This is rehearsal (test) data: say so in the headline. ' : '')
            ."\nheadline: the single most important thing now, at most 15 words."
            ."\npoints: 3 to 6 short points on what is happening (coverage, trouble spots, results so far, anything changing since the previous brief)."
            ."\nactions: 1 to 4 concrete things the situation room should do next, most urgent first."
            ."\n\n<facts>\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n</facts>",
            [
                'type' => 'object',
                'properties' => [
                    'headline' => ['type' => 'string'],
                    'points' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['headline', 'points', 'actions'],
                'additionalProperties' => false,
            ],
            'medium',
            4000,
        );

        $brief = SituationBrief::create([
            'rehearsal' => $rehearsal,
            'headline' => mb_substr((string) $answer['headline'], 0, 300),
            'points' => array_values(array_slice(array_filter(array_map('strval', (array) $answer['points'])), 0, 8)),
            'actions' => array_values(array_slice(array_filter(array_map('strval', (array) $answer['actions'])), 0, 6)),
            'facts' => $facts,
            'written_by' => mb_substr($by, 0, 100),
        ]);

        $this->notify($brief);

        return $brief;
    }

    /**
     * Background pass: once per half-hour slot, for real and rehearsal data
     * separately, when there was activity in the last hour.
     */
    public function step(): void
    {
        if (! $this->ai->enabled()) {
            return;
        }

        $now = now();
        $slot = $now->format('Y-m-d H:').str_pad((string) (intdiv((int) $now->format('i'), self::EVERY_MINUTES) * self::EVERY_MINUTES), 2, '0', STR_PAD_LEFT);

        foreach ([false, true] as $rehearsal) {
            $key = 'runner.brief.'.($rehearsal ? 'rehearsal' : 'real');
            if (Settings::get($key) === $slot || ! $this->active($rehearsal)) {
                continue;
            }
            Settings::set($key, $slot);

            try {
                $this->write($rehearsal);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Anything reported in the last hour (agents checking in, results, incidents).
     */
    public function active(bool $rehearsal): bool
    {
        $since = now()->subHour();

        return Incident::query()->where('rehearsal', $rehearsal)->where('reported_at', '>=', $since)->exists()
            || Result::query()->where('rehearsal', $rehearsal)->where('submitted_at', '>=', $since)->exists()
            || Presence::query()->where('rehearsal', $rehearsal)->where('confirmed_at', '>=', $since)->exists();
    }

    private function notify(SituationBrief $brief): void
    {
        try {
            app(PushNotifier::class)->toTopic('briefs', [
                'title' => ($brief->rehearsal ? '[Rehearsal] ' : '').'Situation brief, '.$brief->created_at->timezone(config('election.timezone', 'Africa/Lagos'))->format('g:i A'),
                'body' => $brief->headline,
                'url' => route('brief', absolute: false).'#brief-'.$brief->id,
                'tag' => 'brief',
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
