<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\OfficialResult;
use App\Models\PollingUnit;
use App\Services\Collation;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Entering INEC's IReV result for a PU. The form deliberately doesn't show
 * our PVT figures, so they can't influence what is typed; the comparison
 * appears once it is saved.
 */
class OfficialResultController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $code = PollingUnit::normalizeCode((string) $request->query('code'));

        if ($code !== '') {
            return PollingUnit::query()->where('code', $code)->exists()
                ? redirect()->route('official.pu', $code)
                : back()->with('error', "No polling unit {$request->query('code')} in the register.");
        }

        return view('official.index', [
            'recent' => OfficialResult::query()->with('pollingUnit:code,name')->latest('updated_at')->limit(20)->get(),
            'entered' => OfficialResult::query()->where('irev_status', OfficialResult::UPLOADED)->count(),
            'notUploaded' => OfficialResult::query()->where('irev_status', OfficialResult::NOT_UPLOADED)->count(),
            'units' => PollingUnit::query()->count(),
        ]);
    }

    public function edit(string $code): View
    {
        $unit = PollingUnit::query()->where('code', PollingUnit::normalizeCode($code))->firstOrFail();

        return view('official.pu', [
            'unit' => $unit,
            'official' => OfficialResult::query()->where('polling_unit_code', $unit->code)->first(),
            'parties' => Collation::parties(),
        ]);
    }

    public function update(Request $request, string $code): RedirectResponse
    {
        $unit = PollingUnit::query()->where('code', PollingUnit::normalizeCode($code))->firstOrFail();
        $uploaded = $request->input('irev_status', OfficialResult::UPLOADED) === OfficialResult::UPLOADED;
        $number = [$uploaded ? 'required' : 'nullable', 'integer', 'min:0', 'max:100000'];

        $validated = $request->validate([
            'irev_status' => ['required', Rule::in([OfficialResult::UPLOADED, OfficialResult::NOT_UPLOADED])],
            'accredited_voters' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'rejected_votes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'votes' => [$uploaded ? 'required' : 'nullable', 'array'],
            ...collect(Collation::parties())->mapWithKeys(fn (string $party) => ["votes.{$party}" => $number])->all(),
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $official = OfficialResult::updateOrCreate(['polling_unit_code' => $unit->code], [
            'lga' => $unit->lga,
            'ward' => $unit->ward,
            'irev_status' => $validated['irev_status'],
            'accredited_voters' => $validated['accredited_voters'] ?? null,
            'votes' => $uploaded ? array_map('intval', $validated['votes']) : null,
            'rejected_votes' => $validated['rejected_votes'] ?? null,
            'source' => 'manual',
            'note' => $validated['note'] ?? null,
            'entered_by' => $request->user()->name,
        ]);

        Audit::record('official.pu', ($official->wasRecentlyCreated ? 'Entered' : 'Changed')." the IReV result for PU {$unit->code}: ".($uploaded ? json_encode($official->votesByParty()) : 'no upload on IReV'));

        return redirect()->route('official.pu', $unit->code)->with('status', 'Saved. The comparison with our agent\'s figures is below.')->with('warnings', $this->warnings($official, $unit));
    }

    public function destroy(string $code): RedirectResponse
    {
        $official = OfficialResult::query()->where('polling_unit_code', PollingUnit::normalizeCode($code))->firstOrFail();
        $official->delete();
        Audit::record('official.pu.deleted', "Deleted the IReV result for PU {$official->polling_unit_code}");

        return redirect()->route('official')->with('status', "Deleted the IReV result for PU {$official->polling_unit_code}.");
    }

    /**
     * Checks that don't block saving (the sheet may really say this) but
     * are worth a second look.
     *
     * @return list<string>
     */
    private function warnings(OfficialResult $official, PollingUnit $unit): array
    {
        if (! $official->uploaded()) {
            return [];
        }

        $warnings = [];
        $cast = $official->totalValidVotes() + (int) $official->rejected_votes;

        if ($official->accredited_voters !== null && $cast > $official->accredited_voters) {
            $warnings[] = "Votes cast ({$cast}) are more than accredited voters ({$official->accredited_voters}): over-voting.";
        }
        if ($unit->registered_voters && ($official->accredited_voters ?? $cast) > $unit->registered_voters) {
            $warnings[] = 'More accredited voters than registered ('.number_format($unit->registered_voters).').';
        }

        return $warnings;
    }
}
