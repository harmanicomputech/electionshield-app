<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\MaterialReport;
use App\Models\PollingUnit;
use App\Models\Presence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Election-day status of every polling unit: agent check-in, the latest
 * materials report, the counted result and unresolved incidents. Real and
 * rehearsal data are never mixed.
 */
class PuMonitor
{
    public const MATERIALS = ['arrived', 'incomplete', 'not_arrived'];

    public function __construct(private bool $rehearsal) {}

    /**
     * @return Collection<string, PuStatus> by PU code
     */
    public function units(?string $lga = null, ?string $ward = null): Collection
    {
        $area = fn (Builder $query) => $query
            ->when($lga !== null, fn (Builder $query) => $query->where('lga', $lga))
            ->when($ward !== null, fn (Builder $query) => $query->where('ward', $ward));
        $records = fn (Builder $query) => $area($query)->where('rehearsal', $this->rehearsal);

        $statuses = collect();
        $status = function (string $code, ?string $lga, ?string $ward) use ($statuses): PuStatus {
            return $statuses[$code] ??= new PuStatus($code, $lga ?: Collation::UNKNOWN, $ward ?: Collation::UNKNOWN);
        };

        foreach ($area(PollingUnit::query())->get() as $unit) {
            $status($unit->code, $unit->lga, $unit->ward)->unit = $unit;
        }

        // The first check-in counts.
        foreach ($records(Presence::query())->orderBy('confirmed_at')->get() as $presence) {
            $row = $status($presence->polling_unit_code, $presence->lga, $presence->ward);
            $row->checkedInAt ??= $presence->confirmed_at;
        }

        // The latest materials report is the PU's current status.
        foreach ($records(MaterialReport::query())->orderBy('reported_at')->orderBy('ussd_id')->get() as $report) {
            $status($report->polling_unit_code, $report->lga, $report->ward)->materials = $report;
        }

        foreach ((new Collation($this->rehearsal))->results() as $code => $result) {
            if (($lga === null || $result->lga === $lga) && ($ward === null || $result->ward === $ward)) {
                $status($code, $result->lga, $result->ward)->result = $result;
            }
        }

        foreach ($records(Incident::query())->unresolved()->get(['polling_unit_code', 'lga', 'ward', 'urgent']) as $incident) {
            $row = $status($incident->polling_unit_code, $incident->lga, $incident->ward);
            $row->openIncidents++;
            $row->urgentIncidents += $incident->urgent ? 1 : 0;
        }

        return $statuses->sortKeys(SORT_NATURAL);
    }

    /**
     * @param  Collection<string, PuStatus>  $units
     * @param  callable(PuStatus): string  $area
     * @return array<string, MonitorTally> alphabetical
     */
    public function tally(Collection $units, callable $area): array
    {
        $tallies = [];

        foreach ($units as $unit) {
            $name = $area($unit);
            ($tallies[$name] ??= new MonitorTally($name))->add($unit);
        }

        ksort($tallies, SORT_NATURAL | SORT_FLAG_CASE);

        return $tallies;
    }

    /**
     * @param  Collection<string, PuStatus>  $units
     */
    public function total(Collection $units): MonitorTally
    {
        $total = new MonitorTally('Total');
        $units->each(fn (PuStatus $unit) => $total->add($unit));

        return $total;
    }
}
