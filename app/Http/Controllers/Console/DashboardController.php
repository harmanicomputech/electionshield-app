<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\Collation;
use App\Services\PuMonitor;
use App\Services\SpreadTracker;
use App\Support\Settings;
use Illuminate\View\View;

/**
 * The PVT dashboard: totals, the win condition and weak links.
 */
class DashboardController extends Controller
{
    public function index(): View
    {
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
