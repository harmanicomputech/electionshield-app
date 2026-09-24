<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Services\PuMonitor;
use App\Services\PuStatus;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The PU monitoring board: check-in, materials, results and open incidents,
 * state → LGA → ward → PU.
 */
class MonitorController extends Controller
{
    public function index(): View
    {
        $monitor = new PuMonitor(Settings::showingRehearsal());
        $units = $monitor->units();

        return view('monitor.index', [
            'total' => $monitor->total($units),
            'areas' => $monitor->tally($units, fn (PuStatus $unit) => $unit->lga),
        ]);
    }

    public function lga(string $lga): View
    {
        $monitor = new PuMonitor(Settings::showingRehearsal());
        $units = $monitor->units($lga);
        abort_if($units->isEmpty(), 404);

        return view('monitor.lga', [
            'lga' => $lga,
            'total' => $monitor->total($units),
            'areas' => $monitor->tally($units, fn (PuStatus $unit) => $unit->ward),
        ]);
    }

    public function ward(Request $request, string $lga, string $ward): View
    {
        $units = (new PuMonitor(Settings::showingRehearsal()))->units($lga, $ward);
        abort_if($units->isEmpty(), 404);

        $problems = $request->boolean('problems');

        return view('monitor.ward', [
            'lga' => $lga,
            'ward' => $ward,
            'problems' => $problems,
            'count' => $units->count(),
            'attention' => $units->filter(fn (PuStatus $unit) => $unit->needsAttention())->count(),
            'units' => $problems ? $units->filter(fn (PuStatus $unit) => $unit->needsAttention()) : $units,
        ]);
    }
}
