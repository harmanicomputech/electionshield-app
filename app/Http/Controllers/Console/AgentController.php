<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\PollingUnit;
use App\Services\PuMonitor;
use App\Services\PuStatus;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who to call on election day: every agent with their PU's status, and the
 * PUs that have gone silent (no check-in, no result, or no agent at all).
 * Shows phone numbers, so the service worker never caches it.
 */
class AgentController extends Controller
{
    public const VIEWS = [
        'all' => 'All agents',
        'no-checkin' => 'No check-in',
        'no-result' => 'No result',
        'no-agent' => 'No agent',
    ];

    public function index(Request $request): View
    {
        [$view, $rows, $counts] = $this->rows($request);
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('agents.index', [
            'view' => $view,
            'counts' => $counts,
            'rows' => new LengthAwarePaginator($rows->forPage($page, 40)->values(), $rows->count(), 40, $page, ['path' => $request->url(), 'query' => $request->query()]),
            'lgas' => PollingUnit::query()->whereNotNull('lga')->distinct()->orderBy('lga')->pluck('lga'),
        ]);
    }

    /**
     * The current list as CSV, for field supervisors (admins: it has phone numbers).
     */
    public function export(Request $request): StreamedResponse
    {
        [$view, $rows] = $this->rows($request);
        Audit::record('agents.export', 'Exported the "'.self::VIEWS[$view].'" agent list ('.$rows->count().' PUs)'.($request->query('lga') ? " for {$request->query('lga')}" : ''));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['lga', 'ward', 'pu_code', 'inec_code', 'pu_name', 'agent', 'phone', 'checked_in', 'materials', 'result_reference'], escape: '\\');

            foreach ($rows as $row) {
                $agents = $row['agents']->isEmpty() ? collect([null]) : $row['agents'];

                foreach ($agents as $agent) {
                    fputcsv($out, [
                        $row['status']->lga, $row['status']->ward, $row['status']->code, $row['status']->unit?->inecCode(), $row['status']->name(),
                        $agent?->name, $agent?->phone_number,
                        $row['status']->checkedInAt?->setTimezone(config('election.timezone'))->format('Y-m-d H:i'),
                        $row['status']->materialsStatus(), $row['status']->result?->reference,
                    ], escape: '\\');
                }
            }

            fclose($out);
        }, 'election-shield-agents-'.now()->setTimezone(config('election.timezone'))->format('Y-m-d-Hi').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{0: string, 1: Collection<int, array{status: PuStatus, agents: Collection<int, Agent>}>, 2: array<string, int>}
     */
    private function rows(Request $request): array
    {
        $validated = $request->validate([
            'view' => ['nullable', Rule::in(array_keys(self::VIEWS))],
            'lga' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $view = $validated['view'] ?? 'all';
        $lga = match (true) {
            ($validated['lga'] ?? null) === 'all' => null,
            filled($validated['lga'] ?? null) => $validated['lga'],
            default => $request->user()->lga,
        };
        $request->attributes->set('agents_lga', $lga);
        $search = mb_strtolower(trim((string) ($validated['q'] ?? '')));

        $units = (new PuMonitor(Settings::showingRehearsal()))->units($lga);
        $agents = Agent::query()->when($lga !== null, fn ($query) => $query->whereIn('polling_unit_code', $units->keys()->map(fn ($code) => (string) $code)))
            ->orderBy('name')->get()->groupBy('polling_unit_code');

        $all = $units->map(fn (PuStatus $status, $code) => ['status' => $status, 'agents' => $agents->get((string) $code, collect())]);

        if ($search !== '') {
            // 0802… and +234802… are the same number: match without the prefix.
            $digits = preg_replace('/^(234|0)/', '', preg_replace('/\D/', '', $search));
            $all = $all->filter(fn (array $row) => str_contains(mb_strtolower($row['status']->name()), $search)
                || ($digits !== '' && str_contains((string) $row['status']->code, $digits))
                || $row['agents']->contains(fn (Agent $agent) => str_contains(mb_strtolower($agent->name), $search) || ($digits !== '' && str_contains(preg_replace('/\D/', '', $agent->phone_number), $digits))));
        }

        $filters = [
            'all' => fn (array $row) => $row['agents']->isNotEmpty(),
            'no-checkin' => fn (array $row) => $row['agents']->isNotEmpty() && $row['status']->checkedInAt === null,
            'no-result' => fn (array $row) => $row['agents']->isNotEmpty() && $row['status']->result === null,
            'no-agent' => fn (array $row) => $row['agents']->isEmpty(),
        ];

        $counts = array_map(fn ($filter) => $all->filter($filter)->count(), $filters);

        return [$view, $all->filter($filters[$view])->values(), $counts];
    }
}
