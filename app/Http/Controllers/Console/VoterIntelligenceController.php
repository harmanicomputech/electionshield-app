<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\VoterStat;
use App\Services\VoterIntelligence;
use App\Services\VoterStatImporter;
use App\Support\Audit;
use App\Support\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Voter intelligence: the state → LGA → ward → polling unit drill-down with
 * registered voters and who the voters are, from sourced figures only.
 */
class VoterIntelligenceController extends Controller
{
    public function __construct(private VoterIntelligence $intelligence) {}

    public function state(Request $request): View
    {
        $state = config('election.state', 'Ebonyi');

        return $this->page($request, 'state', [
            'title' => "{$state} State",
            'crumbs' => [],
            'children' => $this->intelligence->children('state'),
            'childLabel' => 'LGAs',
            'childLink' => fn (array $child) => route('intelligence.lga', $child['name']),
            'chain' => $this->intelligence->chain('state'),
            'official' => $this->intelligence->officialTotal('state', ''),
        ]);
    }

    public function lga(Request $request, string $lga): View
    {
        abort_unless(PollingUnit::query()->where('lga', $lga)->exists(), 404);

        return $this->page($request, 'lga', [
            'title' => "{$lga} LGA",
            'crumbs' => [[route('intelligence'), config('election.state', 'Ebonyi')]],
            'children' => $this->intelligence->children('lga', $lga),
            'childLabel' => 'wards',
            'childLink' => fn (array $child) => route('intelligence.ward', [$lga, $child['name']]),
            'chain' => $this->intelligence->chain('lga', $lga),
            'official' => $this->intelligence->officialTotal('lga', $lga),
        ]);
    }

    public function ward(Request $request, string $lga, string $ward): View
    {
        abort_unless(PollingUnit::query()->where('lga', $lga)->where('ward', $ward)->exists(), 404);

        return $this->page($request, 'ward', [
            'title' => "{$ward} ward",
            'subtitle' => "{$lga} LGA",
            'crumbs' => [[route('intelligence'), config('election.state', 'Ebonyi')], [route('intelligence.lga', $lga), $lga]],
            'children' => $this->intelligence->children('ward', $lga, $ward),
            'childLabel' => 'polling units',
            'childLink' => fn (array $child) => route('intelligence.pu', $child['code']),
            'chain' => $this->intelligence->chain('ward', $lga, $ward),
            'official' => $this->intelligence->officialTotal('ward', VoterStat::areaKey('ward', $lga, $ward)),
        ]);
    }

    public function pu(Request $request, string $code): View
    {
        $unit = PollingUnit::query()->where('code', PollingUnit::normalizeCode($code))->firstOrFail();

        return $this->page($request, 'pu', [
            'title' => $unit->name ?: $unit->code,
            'subtitle' => $unit->inecCode()." · {$unit->ward} ward, {$unit->lga} LGA",
            'crumbs' => [[route('intelligence'), config('election.state', 'Ebonyi')], [route('intelligence.lga', $unit->lga), $unit->lga], [route('intelligence.ward', [$unit->lga, $unit->ward]), $unit->ward]],
            'children' => collect(),
            'childLabel' => '',
            'childLink' => fn () => null,
            'chain' => $this->intelligence->chain('pu', unit: $unit),
            'official' => $this->intelligence->officialTotal('pu', $unit->code),
            'unit' => $unit,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(Request $request, string $level, array $data): View
    {
        $children = $data['children'];
        $profile = $this->intelligence->profile($data['chain']);
        $registerVoters = $level === 'pu'
            ? $data['unit']->registered_voters
            : ($children->every(fn ($child) => $child['units_with_voters'] === $child['units']) && $children->isNotEmpty() ? $children->sum('register_voters') : null);

        $sources = collect($profile)->flatMap(fn ($dimension) => $dimension['sources'])
            ->when($data['official'], fn ($sources) => $sources->push(['source' => $data['official']->source, 'url' => $data['official']->source_url, 'as_of' => $data['official']->as_of]))
            ->unique('source')->values();

        return view('intelligence.show', [...$data,
            'level' => $level,
            'profile' => $profile,
            'insights' => $this->intelligence->insights($profile, $children, $data['childLabel']),
            'registerVoters' => $registerVoters,
            'unitsWithVoters' => $level === 'pu' ? (int) ($data['unit']->registered_voters !== null) : $children->sum('units_with_voters'),
            'units' => $level === 'pu' ? 1 : $children->sum('units'),
            'sources' => $sources,
            'maxVoters' => max(1, (int) $children->max('voters')),
            'canManage' => $request->user()->can(Permission::MANAGE_VOTER_DATA),
            'canExport' => $request->user()->can(Permission::EXPORT_DATA),
            'loaded' => $level === 'state' && $request->user()->can(Permission::MANAGE_VOTER_DATA)
                ? VoterStat::query()->selectRaw('source, count(*) as figures, min(as_of) as as_of, max(updated_at) as updated_at')->groupBy('source')->orderBy('source')->get()
                : collect(),
        ]);
    }

    public function importFigures(Request $request, VoterStatImporter $importer): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel']]);

        try {
            $result = $importer->import($request->file('file')->getRealPath(), $request->user()->name);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['errors']) {
            return back()->with('error', 'Nothing was saved. Fix these rows and upload again: '.implode('; ', $result['errors']));
        }

        Audit::record('voter_data.imported', "Loaded {$result['saved']} voter figures from ".$request->file('file')->getClientOriginalName());

        return back()->with('status', "{$result['saved']} figures saved.");
    }

    public function importVoters(Request $request, VoterStatImporter $importer): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel']]);

        try {
            $result = $importer->importRegisteredVoters($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['errors']) {
            return back()->with('error', 'Nothing was saved. Fix these rows and upload again: '.implode('; ', $result['errors']));
        }

        Audit::record('voter_data.registered_voters', "Loaded registered voters for {$result['saved']} polling units from ".$request->file('file')->getClientOriginalName());

        return back()->with('status', "Registered voters saved for {$result['saved']} polling units. Upload the register (Download register) to the USSD service too, so agents can't enter more accredited voters than registered.");
    }

    public function removeSource(Request $request): RedirectResponse
    {
        $validated = $request->validate(['source' => ['required', 'string', 'max:190']]);
        $removed = VoterStat::query()->where('source', $validated['source'])->delete();
        Audit::record('voter_data.removed', "Removed {$removed} voter figures from \"{$validated['source']}\"");

        return back()->with('status', "Removed {$removed} figures from \"{$validated['source']}\".");
    }

    /**
     * An example file for the figures upload, with every column.
     */
    public function template(): StreamedResponse
    {
        $lga = PollingUnit::query()->orderBy('code')->first();

        return response()->streamDownload(function () use ($lga) {
            $out = fopen('php://output', 'w');
            fputcsv($out, VoterStatImporter::COLUMNS);
            // Example rows only: the upload refuses any row whose source starts with EXAMPLE.
            $source = 'EXAMPLE (replace with the real source, e.g. INEC PVC statistics)';
            $url = 'https://inecnigeria.org/statistics/pvc';
            fputcsv($out, ['state', '', '', '', 'pvc', 'collected', '1500000', '', $source, $url, '2027-01-20', 'Replace the numbers with the real figures']);
            fputcsv($out, ['lga', $lga?->lga ?? 'Abakaliki', '', '', 'pvc', 'uncollected', '12000', '', $source, $url, '2027-01-20', '']);
            fputcsv($out, ['ward', $lga?->lga ?? 'Abakaliki', $lga?->ward ?? 'Abakpa', '', 'registered', 'total', '9500', '', $source, '', '2027-01-20', '']);
            fputcsv($out, ['pu', '', '', $lga?->inecCode() ?? '11/01/01/001', 'first_time', 'new', '85', '', $source, '', '2026-12-01', '']);
            fclose($out);
        }, 'voter-figures-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * The register with voter numbers, in the format both apps import.
     */
    public function register(): StreamedResponse
    {
        Audit::record('voter_data.exported', 'Downloaded the polling unit register');

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['code', 'name', 'ward', 'lga', 'registered_voters', 'latitude', 'longitude']);
            foreach (PollingUnit::query()->orderBy('code')->lazy(500) as $unit) {
                $approximate = $unit->location_source === null || $unit->hasApproximateLocation();
                fputcsv($out, [$unit->inecCode(), $unit->name, $unit->ward, $unit->lga, $unit->registered_voters, $approximate ? $unit->latitude : '', $approximate ? $unit->longitude : '']);
            }
            fclose($out);
        }, 'ebonyi-polling-units.csv', ['Content-Type' => 'text/csv']);
    }
}
