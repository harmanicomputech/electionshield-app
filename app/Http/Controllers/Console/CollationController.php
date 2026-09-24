<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Services\Collation;
use App\Support\Settings;
use Illuminate\View\View;

/**
 * Collation drill-down: state → LGA → ward → PU.
 */
class CollationController extends Controller
{
    public function index(): View
    {
        $collation = new Collation(Settings::showingRehearsal());

        return view('collation.index', [
            'state' => $collation->state(),
            'areas' => $collation->lgas(),
        ]);
    }

    public function lga(string $lga): View
    {
        $collation = new Collation(Settings::showingRehearsal());
        $wards = $collation->wards($lga);
        abort_if($wards === [], 404);

        return view('collation.lga', ['lga' => $lga, 'areas' => $wards]);
    }

    public function ward(string $lga, string $ward): View
    {
        $units = (new Collation(Settings::showingRehearsal()))->units($lga, $ward);
        abort_if($units === [], 404);

        return view('collation.ward', ['lga' => $lga, 'ward' => $ward, 'units' => $units]);
    }
}
