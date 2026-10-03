<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\SituationBrief;
use App\Services\Ai\Claude;
use App\Services\Ai\SituationBriefs;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * The situation brief: written by AI every half hour while there is
 * activity, or on request, from the app's live figures.
 */
class BriefController extends Controller
{
    public function index(SituationBriefs $briefs, Claude $ai): View
    {
        $rehearsal = Settings::showingRehearsal();

        return view('brief.index', [
            'briefs' => SituationBrief::query()->where('rehearsal', $rehearsal)->latest('id')->limit(12)->get(),
            'facts' => $briefs->facts($rehearsal),
            'aiOn' => $ai->enabled(),
            'every' => SituationBriefs::EVERY_MINUTES,
        ]);
    }

    public function store(Request $request, SituationBriefs $briefs): RedirectResponse
    {
        try {
            $brief = $briefs->write(Settings::showingRehearsal(), $request->user()->name);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'The brief could not be written: '.$e->getMessage());
        }

        Audit::record('brief.written', 'Asked for a situation brief');

        return redirect()->to(route('brief').'#brief-'.$brief->id)->with('status', 'New situation brief written.');
    }
}
