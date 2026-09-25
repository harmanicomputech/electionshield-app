<?php

namespace App\Http\Controllers\Console;

use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Models\Ec8aPhoto;
use App\Models\Incident;
use App\Models\MaterialReport;
use App\Models\OfficialCollation;
use App\Models\OfficialResult;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\Result;
use App\Services\Collation;
use App\Services\PuMonitor;
use App\Services\ResultComparison;
use App\Services\SpreadTracker;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\View\View;

/**
 * Printable pages: the evidence pack for one PU (petitions) and the
 * one-page situation report (briefings). Both print cleanly or save as PDF
 * from the browser's print dialog.
 */
class ReportController extends Controller
{
    public function evidence(string $code): View
    {
        $unit = PollingUnit::query()->where('code', PollingUnit::normalizeCode($code))->firstOrFail();
        $rehearsal = Settings::showingRehearsal();
        $comparison = (new ResultComparison($rehearsal))->units($unit->lga)->get($unit->code);
        $history = Result::query()->with('votes')->where('polling_unit_code', $unit->code)->where('rehearsal', $rehearsal)->orderBy('submitted_at')->get();

        Audit::record('evidence.viewed', "Opened the evidence pack for PU {$unit->code}");

        return view('reports.evidence', [
            'unit' => $unit,
            'counted' => $comparison['pvt'] ?? null,
            'official' => OfficialResult::query()->where('polling_unit_code', $unit->code)->first(),
            'diff' => $comparison['diff'] ?? [],
            'flags' => $comparison['flags'] ?? [],
            'history' => $history,
            'photos' => Ec8aPhoto::query()->whereIn('result_reference', $history->pluck('reference'))->orderBy('created_at')->get(),
            'incidents' => Incident::query()->where('polling_unit_code', $unit->code)->where('rehearsal', $rehearsal)->orderBy('reported_at')->get(),
            'presence' => Presence::query()->where('polling_unit_code', $unit->code)->where('rehearsal', $rehearsal)->orderBy('confirmed_at')->first(),
            'materials' => MaterialReport::query()->where('polling_unit_code', $unit->code)->where('rehearsal', $rehearsal)->orderBy('reported_at')->get(),
            'ward' => OfficialCollation::query()->where(['level' => 'ward', 'lga' => $unit->lga, 'ward' => $unit->ward])->first(),
            'lga' => OfficialCollation::query()->where(['level' => 'lga', 'lga' => $unit->lga, 'ward' => ''])->first(),
            'rehearsal' => $rehearsal,
        ]);
    }

    public function sitrep(): View
    {
        $rehearsal = Settings::showingRehearsal();
        $collation = new Collation($rehearsal);
        $state = $collation->state();
        $tracker = new SpreadTracker($state, $collation->lgas());
        $monitor = new PuMonitor($rehearsal);
        $comparison = new ResultComparison($rehearsal);
        $incidents = Incident::query()->where('rehearsal', $rehearsal);

        return view('reports.sitrep', [
            'state' => $state,
            'tracker' => $tracker,
            'field' => $monitor->total($monitor->units()),
            'incidentsByType' => (clone $incidents)->selectRaw('coalesce(type_label, type) as label, count(*) as total, sum(case when resolved_at is null then 1 else 0 end) as open')->groupBy('label')->orderByDesc('total')->get(),
            'urgentOpen' => (clone $incidents)->where('urgent', true)->whereNull('resolved_at')->count(),
            'discrepancies' => $comparison->flagged($comparison->units())->filter(fn ($row) => in_array('discrepancy', $row['flags'], true))->take(10),
            'pendingCorrections' => Result::query()->where('rehearsal', $rehearsal)->where('status', ResultStatus::Pending)->whereNotNull('corrects_reference')->count(),
            'rehearsal' => $rehearsal,
        ]);
    }
}
