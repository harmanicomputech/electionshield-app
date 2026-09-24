<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Services\Collation;
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
        $collation = new Collation(Settings::showingRehearsal());
        $state = $collation->state();
        $tracker = new SpreadTracker($state, $collation->lgas());

        return view('dashboard.index', [
            'state' => $state,
            'tracker' => $tracker,
            'duplicates' => $collation->duplicateCount(),
        ]);
    }

    public function spread(): View
    {
        $collation = new Collation(Settings::showingRehearsal());
        $tracker = new SpreadTracker($collation->state(), $collation->lgas());

        return view('dashboard.spread', ['tracker' => $tracker]);
    }
}
