<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\OfficialCollation;
use App\Services\Collation;
use App\Services\ResultComparison;
use App\Support\Audit;
use App\Support\Settings;
use App\Support\Time;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Our PVT figures against INEC's official ones, and the evidence export.
 */
class CompareController extends Controller
{
    public function index(): View
    {
        $comparison = new ResultComparison(Settings::showingRehearsal());
        $units = $comparison->units();

        return view('compare.index', [
            'collations' => $comparison->collations(),
            'flagged' => $comparison->flagged($units),
            'summary' => $this->summary($units),
        ]);
    }

    public function lga(string $lga): View
    {
        $comparison = new ResultComparison(Settings::showingRehearsal());
        $collations = $comparison->collations($lga);
        abort_if($collations === [], 404);
        $units = $comparison->units($lga);

        return view('compare.lga', [
            'lga' => $lga,
            'declared' => OfficialCollation::query()->where(['level' => OfficialCollation::LGA, 'lga' => $lga])->first(),
            'lgaRow' => collect($comparison->collations())->firstWhere('name', $lga),
            'collations' => $collations,
            'units' => $units,
            'flagged' => $comparison->flagged($units),
            'summary' => $this->summary($units),
        ]);
    }

    /**
     * CSV for petitions: every flagged PU (or every compared PU with
     * ?all=1) with our agent's EC8A, IReV's figures and the differences.
     */
    public function export(Request $request): StreamedResponse
    {
        $comparison = new ResultComparison(Settings::showingRehearsal());
        $units = $comparison->units($request->query('lga') ?: null);
        $rows = $request->boolean('all') ? $units : $comparison->flagged($units);
        $parties = Collation::parties();

        Audit::record('compare.export', 'Exported '.$rows->count().' PU comparisons'.($request->query('lga') ? " for {$request->query('lga')}" : ''));

        return response()->streamDownload(function () use ($rows, $parties) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'lga', 'ward', 'pu_code', 'inec_code', 'pu_name', 'registered_voters', 'flags',
                'pvt_reference', 'pvt_agent', 'pvt_agent_phone', 'pvt_submitted_at',
                ...array_map(fn ($party) => "pvt_{$party}", $parties), 'pvt_accredited', 'pvt_rejected',
                'irev_status', ...array_map(fn ($party) => "irev_{$party}", $parties), 'irev_accredited', 'irev_rejected', 'irev_entered_by',
                ...array_map(fn ($party) => "diff_{$party}", $parties),
            ], escape: '\\');

            foreach ($rows as $row) {
                $pvt = $row['pvt'];
                $official = $row['official'];
                $pvtVotes = $pvt?->votesByParty() ?? [];
                $officialVotes = $official?->uploaded() ? $official->votesByParty() : [];

                fputcsv($out, [
                    $row['lga'], $row['ward'], $row['code'], $row['unit']?->inecCode() ?? $row['code'], $row['unit']?->name, $row['unit']?->registered_voters,
                    implode(' ', $row['flags']),
                    $pvt?->reference, $pvt?->agent_name, $pvt?->agent_phone, $pvt ? Time::local($pvt->submitted_at, 'Y-m-d H:i T') : null,
                    ...array_map(fn ($party) => $pvt ? ($pvtVotes[$party] ?? 0) : null, $parties), $pvt?->accredited_voters, $pvt?->rejected_votes,
                    $official?->irev_status,
                    ...array_map(fn ($party) => $officialVotes === [] ? null : $officialVotes[$party], $parties), $official?->accredited_voters, $official?->rejected_votes, $official?->entered_by,
                    ...array_map(fn ($party) => $row['diff'][$party] ?? null, $parties),
                ], escape: '\\');
            }

            fclose($out);
        }, 'election-shield-comparison-'.now()->setTimezone(config('election.timezone'))->format('Y-m-d-Hi').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $units
     * @return array<string, int>
     */
    private function summary($units): array
    {
        $has = fn (string $flag) => $units->filter(fn (array $row) => in_array($flag, $row['flags'], true))->count();

        return [
            'compared' => $units->filter(fn (array $row) => $row['pvt'] && $row['official']?->uploaded())->count(),
            'discrepancy' => $has('discrepancy'),
            'no_upload' => $has('no_upload'),
            'not_entered' => $has('not_entered'),
            'no_pvt' => $has('no_pvt'),
        ];
    }
}
