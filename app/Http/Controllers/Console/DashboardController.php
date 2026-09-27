<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\Collation;
use App\Services\LgaMap;
use App\Services\PuMonitor;
use App\Services\SpreadTracker;
use App\Support\Permission;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The PVT dashboard: totals, the win condition and weak links.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()->cannot(Permission::VIEW_DASHBOARDS)) {
            return $request->user()->can(Permission::SUBMIT_FIELD_REPORTS) ? redirect()->route('field') : redirect()->route('account');
        }

        $rehearsal = Settings::showingRehearsal();
        $collation = new Collation($rehearsal);
        $state = $collation->state();
        $tracker = new SpreadTracker($state, $collation->lgas());
        $monitor = new PuMonitor($rehearsal);

        return view('dashboard.index', [
            'state' => $state,
            'tracker' => $tracker,
            'duplicates' => $collation->duplicateCount(),
            'field' => $monitor->total($monitor->units()),
            'map' => (new LgaMap($rehearsal))->build(['share', 'results', 'checkin', 'incidents'], fn ($lga) => route('collation.lga', $lga)),
            'urgentOpen' => Incident::query()->where('rehearsal', $rehearsal)->where('urgent', true)->withResponseStatus(Incident::OPEN)->count(),
        ]);
    }

    public function spread(): View
    {
        $collation = new Collation(Settings::showingRehearsal());
        $tracker = new SpreadTracker($collation->state(), $collation->lgas());

        return view('dashboard.spread', ['tracker' => $tracker]);
    }
}
