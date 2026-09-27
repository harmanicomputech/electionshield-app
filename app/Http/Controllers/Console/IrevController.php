<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\IrevDocument;
use App\Models\OfficialResult;
use App\Models\PollingUnit;
use App\Services\Irev\IrevWatcher;
use App\Services\IrevSheetReader;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Following an election on IReV: choosing it, switching automatic fetching
 * on or off, what has been fetched, and matching PUs by hand.
 */
class IrevController extends Controller
{
    public function index(Request $request, IrevWatcher $watcher, IrevSheetReader $reader): View
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(array_keys(IrevDocument::LABELS))]])['status'] ?? null;
        $electionId = (int) Settings::get('irev.election_id');
        $documents = IrevDocument::query()->where('irev_election_id', $electionId);

        return view('official.irev', [
            'configured' => $watcher->configured(),
            'automatic' => $watcher->automatic(),
            'election' => Settings::get('irev.election_name'),
            'lastSweep' => Settings::get('irev.last_sweep_at'),
            'lastFullSweep' => Settings::get('irev.last_full_sweep_at'),
            'lastError' => Settings::get('irev.last_error'),
            'reader' => $reader->enabled(),
            'counts' => (clone $documents)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'unchecked' => OfficialResult::query()->where('needs_check', true)->count(),
            'status' => $status,
            'rows' => $status ? (clone $documents)->where('status', $status)->orderBy('lga_name')->orderBy('ward_name')->orderBy('irev_pu_code')->paginate(50)->withQueryString() : null,
            'choices' => session('irev_choices'),
        ]);
    }

    /**
     * Ask IReV for this state's elections, to choose which to follow.
     */
    public function elections(IrevWatcher $watcher): RedirectResponse
    {
        try {
            $choices = $watcher->stateElections();
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Could not reach IReV: '.$e->getMessage());
        }

        return back()->with($choices ? 'status' : 'error', $choices ? 'Choose the election to follow below.' : 'IReV lists no election for '.config('election.state', 'Ebonyi').' yet. INEC usually publishes it a few days before election day.')
            ->with('irev_choices', $choices);
    }

    public function follow(Request $request, IrevWatcher $watcher): RedirectResponse
    {
        $validated = $request->validate([
            'election' => ['required', 'string', 'regex:/^[a-f0-9]{24}\|\d+\|.+$/s'],
        ]);
        [$oid, $id, $name] = explode('|', $validated['election'], 3);

        $watcher->follow($oid, (int) $id, $name);
        $watcher->setAutomatic(true);
        Audit::record('irev.follow', "Following \"{$name}\" on IReV (automatic fetching on)");

        return redirect()->route('official.irev')->with('status', "Following {$name}. New sheets are fetched and read every couple of minutes while the pinger runs; press Check now to start.");
    }

    public function automatic(Request $request, IrevWatcher $watcher): RedirectResponse
    {
        $on = $request->boolean('on');
        $watcher->setAutomatic($on);
        Audit::record('irev.automatic', 'Automatic IReV fetching '.($on ? 'on' : 'off'));

        return back()->with('status', $on ? 'Automatic fetching is on.' : 'Automatic fetching is off. Nothing more is fetched until you switch it on or press Check now.');
    }

    public function check(IrevWatcher $watcher): RedirectResponse
    {
        if (! $watcher->configured()) {
            return back()->with('error', 'Choose the election to follow first.');
        }

        @set_time_limit(280);
        $done = $watcher->step(wards: 40, sheets: 10);
        $error = Settings::get('irev.last_error');

        return back()->with($error ? 'error' : 'status', $error ? "IReV: {$error}" : "Looked at {$done['wards']} wards and handled {$done['sheets']} sheets.");
    }

    public function match(Request $request, IrevDocument $document, IrevWatcher $watcher): RedirectResponse
    {
        $request->merge(['code' => PollingUnit::normalizeCode((string) $request->input('code'))]);
        $validated = $request->validate(['code' => ['required', Rule::exists('polling_units', 'code')]], ['code.exists' => 'No PU with that code in our register.']);

        $watcher->matchByHand($document, $validated['code']);
        Audit::record('irev.match', "Matched IReV PU {$document->irev_pu_code} ({$document->pu_name}) to our PU {$validated['code']}");

        return back()->with('status', "Matched {$document->irev_pu_code} to PU {$validated['code']}. Its sheet is fetched on the next check.");
    }
}
