<?php

namespace App\Services;

/**
 * A schematic map of Ebonyi's 13 LGAs: one tile each, placed roughly where
 * the LGA lies (north at the top), so it reads at phone width without
 * boundary data. Each layer gives every tile a colour class and a value;
 * the value is always shown as text, so no reading depends on colour.
 */
class LgaMap
{
    /** [column, row] on a 4 × 5 grid. */
    public const LAYOUT = [
        'Ishielu' => [1, 1], 'Ohaukwu' => [2, 1], 'Ebonyi' => [3, 1], 'Izzi' => [4, 1],
        'Ezza North' => [2, 2], 'Abakaliki' => [3, 2],
        'Ezza South' => [2, 3], 'Ikwo' => [3, 3],
        'Ohaozara' => [1, 4], 'Onicha' => [2, 4], 'Afikpo North' => [3, 4],
        'Ivo' => [1, 5], 'Afikpo South' => [3, 5],
    ];

    /** Sequential steps for a percentage, lightest first. */
    private const STEPS = [20 => 'seq1', 40 => 'seq2', 60 => 'seq3', 80 => 'seq4', 101 => 'seq5'];

    public function __construct(private bool $rehearsal) {}

    /**
     * @param  list<string>  $layers  share, results, checkin, incidents
     * @return array{tiles: array<string, array{col: int, row: int, link: string, layers: array<string, array{class: string, value: string, title: string}>}>, layers: array<string, array{label: string, legend: list<array{0: string, 1: string}>}>, focus: ?string}
     */
    public function build(array $layers, callable $link): array
    {
        $collation = new Collation($this->rehearsal);
        $lgas = $collation->lgas();
        $tracker = new SpreadTracker($collation->state(), $lgas);
        $focus = $tracker->focusParty();
        $monitor = new PuMonitor($this->rehearsal);
        $field = $monitor->tally($monitor->units(), fn (PuStatus $unit) => $unit->lga);
        $spread = (float) config('election.spread_share');
        $near = $spread + (float) config('election.near_margin');

        $definitions = [
            'share' => [
                'label' => $focus ? "{$focus} share" : '25% rule',
                'legend' => [['good', "{$near}%+"], ['warn', "{$spread}–{$near}%"], ['bad', "under {$spread}%"], ['none', 'no results']],
            ],
            'results' => ['label' => 'Results in', 'legend' => $this->sequentialLegend()],
            'checkin' => ['label' => 'Agents checked in', 'legend' => $this->sequentialLegend()],
            'incidents' => ['label' => 'Open incidents', 'legend' => [['none', 'none'], ['warn', '1–2'], ['bad', '3+ or urgent']]],
        ];

        $tiles = [];

        foreach (self::LAYOUT as $name => [$col, $row]) {
            $tally = $lgas[$name] ?? null;
            $status = $field[$name] ?? null;
            $cells = [];

            foreach ($layers as $layer) {
                $cells[$layer] = match ($layer) {
                    'share' => $this->shareCell($tally, $focus, $spread, $near),
                    'results' => $this->percentCell($tally?->reported ?? 0, $tally?->units ?? 0, 'PUs reported'),
                    'checkin' => $this->percentCell($status?->checkedIn ?? 0, $status?->units ?? 0, 'agents checked in'),
                    'incidents' => $this->incidentCell($status),
                };
            }

            $tiles[$name] = ['col' => $col, 'row' => $row, 'link' => $link($name), 'layers' => $cells];
        }

        return ['tiles' => $tiles, 'layers' => array_intersect_key($definitions, array_flip($layers)), 'focus' => $focus];
    }

    /**
     * @return array{class: string, value: string, title: string}
     */
    private function shareCell(?Tally $tally, ?string $focus, float $spread, float $near): array
    {
        if (! $focus || ! $tally || $tally->totalVotes() === 0) {
            return ['class' => 'none', 'value' => '—', 'title' => 'No results yet'];
        }

        $share = $tally->share($focus);

        return [
            'class' => $share >= $near ? 'good' : ($share >= $spread ? 'warn' : 'bad'),
            'value' => rtrim(rtrim(number_format($share, 1), '0'), '.').'%',
            'title' => "{$focus} {$share}% of valid votes ({$tally->reported} of {$tally->units} PUs reported)",
        ];
    }

    /**
     * @return array{class: string, value: string, title: string}
     */
    private function percentCell(int $count, int $total, string $what): array
    {
        if ($total === 0) {
            return ['class' => 'none', 'value' => '—', 'title' => 'No polling units'];
        }

        $percent = 100 * $count / $total;
        $class = collect(self::STEPS)->first(fn ($class, $limit) => $percent < $limit) ?? 'seq5';

        return ['class' => $count === 0 ? 'none' : $class, 'value' => round($percent).'%', 'title' => "{$count} of {$total} {$what}"];
    }

    /**
     * @return array{class: string, value: string, title: string}
     */
    private function incidentCell(?MonitorTally $status): array
    {
        $open = $status?->openIncidents ?? 0;
        $urgent = $status?->urgentIncidents ?? 0;

        return [
            'class' => $open === 0 ? 'none' : (($urgent > 0 || $open >= 3) ? 'bad' : 'warn'),
            'value' => (string) $open,
            'title' => $open ? "{$open} unresolved ({$urgent} urgent)" : 'No unresolved incidents',
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function sequentialLegend(): array
    {
        return [['none', '0%'], ['seq1', '1–19%'], ['seq2', '20–39%'], ['seq3', '40–59%'], ['seq4', '60–79%'], ['seq5', '80–100%']];
    }
}
