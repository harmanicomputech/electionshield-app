<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\PollingUnit;
use App\Models\Volunteer;
use App\Models\VoterStat;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * The figures behind the Voter intelligence page: the area's voters, its
 * sub-areas (LGAs, wards or PUs) and a profile of who the voters are.
 *
 * Every figure comes from the PU register or a sourced VoterStat. Where an
 * area has no figures of its own, the profile shows the nearest larger
 * area's (ward → LGA → state → Nigeria), labelled as such; nothing is
 * estimated or scaled down.
 */
class VoterIntelligence
{
    /**
     * The area and the larger areas around it, smallest first.
     *
     * @return list<array{level: string, area: string, label: string}>
     */
    public function chain(string $level, ?string $lga = null, ?string $ward = null, ?PollingUnit $unit = null): array
    {
        $state = config('election.state', 'Ebonyi');
        $chain = [];
        if ($level === 'pu') {
            $chain[] = ['level' => 'pu', 'area' => $unit->code, 'label' => 'this polling unit'];
            [$lga, $ward] = [$unit->lga, $unit->ward];
        }
        if (in_array($level, ['pu', 'ward'], true)) {
            $chain[] = ['level' => 'ward', 'area' => VoterStat::areaKey('ward', $lga, $ward), 'label' => "{$ward} ward"];
        }
        if (in_array($level, ['pu', 'ward', 'lga'], true)) {
            $chain[] = ['level' => 'lga', 'area' => (string) $lga, 'label' => "{$lga} LGA"];
        }
        $chain[] = ['level' => 'state', 'area' => '', 'label' => "{$state} State"];
        $chain[] = ['level' => 'national', 'area' => '', 'label' => 'Nigeria (national)'];

        return $chain;
    }

    /**
     * For each dimension, the figures of the nearest area in the chain that has any.
     *
     * @param  list<array{level: string, area: string, label: string}>  $chain
     * @return array<string, array<string, mixed>>
     */
    public function profile(array $chain): array
    {
        $stats = VoterStat::query()
            ->where(function ($query) use ($chain) {
                foreach ($chain as $link) {
                    $query->orWhere(fn ($query) => $query->where('level', $link['level'])->where('area', $link['area']));
                }
            })
            ->get()
            ->groupBy(fn (VoterStat $stat) => $stat->level.'#'.$stat->area);

        $profile = [];
        foreach (VoterStat::DIMENSIONS as $dimension => [$label, $categories, $hint]) {
            foreach ($chain as $position => $link) {
                $rows = ($stats[$link['level'].'#'.$link['area']] ?? collect())->where('dimension', $dimension);
                if ($rows->isEmpty()) {
                    continue;
                }

                $registered = ($stats[$link['level'].'#'.$link['area']] ?? collect())->first(fn (VoterStat $stat) => $stat->dimension === 'registered' && $stat->category === 'total');
                $profile[$dimension] = [
                    'label' => $label,
                    'hint' => $hint,
                    'rows' => $this->rows($dimension, $rows, $registered?->count),
                    'from' => $link['label'],
                    'level' => $link['level'],
                    'inherited' => $position > 0,
                    'sources' => $rows->map(fn (VoterStat $stat) => ['source' => $stat->source, 'url' => $stat->source_url, 'as_of' => $stat->as_of])->unique('source')->values()->all(),
                ];
                break;
            }
        }

        return $profile;
    }

    /**
     * @param  Collection<int, VoterStat>  $stats
     * @return list<array{key: string, label: string, count: ?int, percent: ?float}>
     */
    private function rows(string $dimension, Collection $stats, ?int $registered): array
    {
        $order = array_keys(VoterStat::DIMENSIONS[$dimension][1]);
        $sum = (int) $stats->sum('count');
        // Shares of the area's voters when INEC's total is known (a list of
        // occupations need not cover everyone), else of the figures given.
        $base = $dimension !== 'registered' && $registered ? $registered : $sum;

        return $stats
            ->sortBy(fn (VoterStat $stat) => [array_search($stat->category, $order, true) === false ? 99 : array_search($stat->category, $order, true), $stat->category])
            ->map(fn (VoterStat $stat) => [
                'key' => $stat->category,
                'label' => VoterStat::categoryLabel($dimension, $stat->category),
                'count' => $stat->count,
                'percent' => $stat->percent ?? ($stat->count !== null && $base > 0 ? round($stat->count / $base * 100, 1) : null),
            ])
            ->values()
            ->all();
    }

    /**
     * INEC's registered-voter total for an area, if one has been loaded.
     */
    public function officialTotal(string $level, string $area): ?VoterStat
    {
        return VoterStat::query()->where(['level' => $level, 'area' => $area, 'dimension' => 'registered', 'category' => 'total'])->first();
    }

    /**
     * The sub-areas of an area with their figures: LGAs of the state, wards
     * of an LGA, or PUs of a ward.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function children(string $level, ?string $lga = null, ?string $ward = null): Collection
    {
        $units = PollingUnit::query()
            ->when($lga !== null, fn ($query) => $query->where('lga', $lga))
            ->when($ward !== null, fn ($query) => $query->where('ward', $ward));

        if ($level === 'ward') {
            $rows = $units->orderBy('code')->get()->map(fn (PollingUnit $unit) => [
                'name' => $unit->name ?: $unit->code,
                'code' => $unit->code,
                'inec_code' => $unit->inecCode(),
                'area' => $unit->code,
                'units' => 1,
                'wards' => null,
                'register_voters' => $unit->registered_voters,
                'units_with_voters' => $unit->registered_voters !== null ? 1 : 0,
            ]);
            $childLevel = 'pu';
        } else {
            $group = $level === 'state' ? 'lga' : 'ward';
            $rows = $units->selectRaw("{$group} as name, count(*) as units, count(distinct ward) as wards, sum(registered_voters) as voters, count(registered_voters) as with_voters")
                ->groupBy($group)->orderBy($group)->get()
                ->map(fn ($row) => [
                    'name' => $row->name,
                    'code' => null,
                    'inec_code' => null,
                    'area' => $group === 'lga' ? $row->name : VoterStat::areaKey('ward', $lga, $row->name),
                    'units' => (int) $row->units,
                    'wards' => $group === 'lga' ? (int) $row->wards : null,
                    'register_voters' => $row->with_voters > 0 ? (int) $row->voters : null,
                    'units_with_voters' => (int) $row->with_voters,
                ]);
            $childLevel = $group;
        }

        $stats = VoterStat::query()->where('level', $childLevel)->whereIn('area', $rows->pluck('area')->all())
            ->whereIn('dimension', ['registered', 'pvc', 'first_time'])->get()->groupBy('area');
        $volunteers = $this->volunteerCounts($childLevel, $lga);
        $agents = $this->agentCounts($childLevel, $lga, $ward);

        return $rows->map(function (array $row) use ($stats, $volunteers, $agents, $childLevel) {
            $figures = $stats[$row['area']] ?? collect();
            $official = $figures->first(fn ($stat) => $stat->dimension === 'registered' && $stat->category === 'total')?->count;
            $complete = $row['units_with_voters'] === $row['units'];

            return [...$row,
                'level' => $childLevel,
                'official_voters' => $official,
                // INEC's total when loaded, else the register's (only when every PU has a figure).
                'voters' => $official ?? ($complete ? $row['register_voters'] : null),
                'pvc_uncollected' => $figures->first(fn ($stat) => $stat->dimension === 'pvc' && $stat->category === 'uncollected')?->count,
                'first_time' => $figures->first(fn ($stat) => $stat->dimension === 'first_time' && $stat->category === 'new')?->count,
                'volunteers' => $volunteers[$row['name']] ?? 0,
                'agents' => $agents[$childLevel === 'pu' ? $row['code'] : $row['name']] ?? 0,
            ];
        })->sortBy([fn ($a, $b) => ($b['voters'] ?? -1) <=> ($a['voters'] ?? -1), fn ($a, $b) => strnatcasecmp($a['name'], $b['name'])])->values();
    }

    /**
     * Volunteers by LGA (for the state) or by ward (for an LGA).
     *
     * @return array<string, int>
     */
    private function volunteerCounts(string $childLevel, ?string $lga): array
    {
        if ($childLevel === 'pu') {
            return [];
        }
        $column = $childLevel === 'lga' ? 'lga' : 'ward';

        return Volunteer::query()->where('rehearsal', Settings::showingRehearsal())
            ->when($childLevel === 'ward', fn ($query) => $query->where('lga', $lga))
            ->whereNotNull($column)->selectRaw("{$column} as name, count(*) as n")->groupBy($column)
            ->pluck('n', 'name')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Agents by LGA, ward or PU code.
     *
     * @return array<string, int>
     */
    private function agentCounts(string $childLevel, ?string $lga, ?string $ward): array
    {
        $column = ['lga' => 'polling_units.lga', 'ward' => 'polling_units.ward', 'pu' => 'polling_units.code'][$childLevel];

        return Agent::query()->join('polling_units', 'polling_units.code', '=', 'agents.polling_unit_code')
            ->when($lga !== null, fn ($query) => $query->where('polling_units.lga', $lga))
            ->when($ward !== null && $childLevel === 'pu', fn ($query) => $query->where('polling_units.ward', $ward))
            ->selectRaw("{$column} as name, count(*) as n")->groupBy($column)
            ->pluck('n', 'name')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Plain-language pointers for targeting, from the figures on the page.
     *
     * @param  array<string, array<string, mixed>>  $profile
     * @param  Collection<int, array<string, mixed>>  $children
     * @return list<array{text: string, from: ?string}>
     */
    public function insights(array $profile, Collection $children, string $childLabel): array
    {
        $insights = [];
        $share = fn (array $row) => $row['percent'] !== null ? ' ('.rtrim(rtrim(number_format($row['percent'], 1), '0'), '.').'%)' : '';
        $largest = fn (string $dimension) => collect($profile[$dimension]['rows'] ?? [])->sortByDesc(fn ($row) => $row['percent'] ?? $row['count'] ?? 0)->first();

        if ($row = $largest('age')) {
            $insights[] = ['text' => "Largest age group: {$row['label']}{$share($row)}.", 'from' => $profile['age']['from']];
        }
        if (isset($profile['gender'])) {
            $rows = collect($profile['gender']['rows'])->keyBy('key');
            if (isset($rows['male'], $rows['female'])) {
                $more = ($rows['female']['percent'] ?? 0) > ($rows['male']['percent'] ?? 0) ? 'female' : 'male';
                $insights[] = ['text' => ($more === 'female' ? 'More women than men' : 'More men than women').": {$rows[$more]['label']}{$share($rows[$more])}.", 'from' => $profile['gender']['from']];
            }
        }
        if ($row = $largest('occupation')) {
            $insights[] = ['text' => "Largest occupation group recorded: {$row['label']}{$share($row)}.", 'from' => $profile['occupation']['from']];
        }
        if (isset($profile['pvc'])) {
            $row = collect($profile['pvc']['rows'])->firstWhere('key', 'uncollected');
            if ($row && $row['count']) {
                $insights[] = ['text' => number_format($row['count']).' PVCs not collected'.$share($row).': these voters can\'t vote until they collect them.', 'from' => $profile['pvc']['from']];
            }
        }
        if (isset($profile['first_time'])) {
            $row = collect($profile['first_time']['rows'])->firstWhere('key', 'new');
            if ($row && $row['count']) {
                $insights[] = ['text' => number_format($row['count']).' first-time voters (newly registered).', 'from' => $profile['first_time']['from']];
            }
        }

        $known = $children->filter(fn ($child) => $child['voters'] !== null);
        if ($known->count() >= 2) {
            $top = $known->take(3)->map(fn ($child) => $child['name'].' ('.number_format($child['voters']).')')->implode(', ');
            $insights[] = ['text' => "Most voters: {$top}.", 'from' => null];
        }
        $uncollected = $children->filter(fn ($child) => $child['pvc_uncollected'])->sortByDesc('pvc_uncollected');
        if ($uncollected->isNotEmpty()) {
            $top = $uncollected->take(3)->map(fn ($child) => $child['name'].' ('.number_format($child['pvc_uncollected']).')')->implode(', ');
            $insights[] = ['text' => "Most PVCs not collected: {$top}.", 'from' => null];
        }
        if ($children->isNotEmpty() && $childLabel !== 'polling units') {
            $none = $children->filter(fn ($child) => $child['volunteers'] === 0)->count();
            if ($none > 0) {
                $insights[] = ['text' => "{$none} of {$children->count()} {$childLabel} have no volunteers signed up yet.", 'from' => null];
            }
        }
        $withoutAgents = $children->sum(fn ($child) => $child['level'] === 'pu' ? ($child['agents'] ? 0 : 1) : 0);
        if ($withoutAgents > 0) {
            $insights[] = ['text' => "{$withoutAgents} of {$children->count()} polling units here have no agent assigned.", 'from' => null];
        }

        return $insights;
    }
}
