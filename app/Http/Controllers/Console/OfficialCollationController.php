<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\OfficialCollation;
use App\Models\PollingUnit;
use App\Services\Collation;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Entering the ward (EC8B) and LGA (EC8C) collations INEC declared.
 */
class OfficialCollationController extends Controller
{
    public function index(): View
    {
        $declared = OfficialCollation::query()->get();

        return view('official.collations', [
            'areas' => PollingUnit::query()->whereNotNull('lga')->select('lga', 'ward')->distinct()->orderBy('lga')->orderBy('ward')->get()->groupBy('lga'),
            'lgaDeclared' => $declared->where('level', OfficialCollation::LGA)->keyBy('lga'),
            'wardDeclared' => $declared->where('level', OfficialCollation::WARD)->groupBy('lga')->map->keyBy('ward'),
        ]);
    }

    public function edit(string $level, string $lga, ?string $ward = null): View
    {
        [$level, $ward] = $this->area($level, $lga, $ward);

        return view('official.collation', [
            'level' => $level,
            'lga' => $lga,
            'ward' => $ward,
            'collation' => OfficialCollation::query()->where(['level' => $level, 'lga' => $lga, 'ward' => $ward])->first(),
            'parties' => Collation::parties(),
        ]);
    }

    public function update(Request $request, string $level, string $lga, ?string $ward = null): RedirectResponse
    {
        [$level, $ward] = $this->area($level, $lga, $ward);
        $number = ['required', 'integer', 'min:0', 'max:5000000'];

        $validated = $request->validate([
            'accredited_voters' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'rejected_votes' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'votes' => ['required', 'array'],
            ...collect(Collation::parties())->mapWithKeys(fn (string $party) => ["votes.{$party}" => $number])->all(),
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $collation = OfficialCollation::updateOrCreate(['level' => $level, 'lga' => $lga, 'ward' => $ward], [
            'accredited_voters' => $validated['accredited_voters'] ?? null,
            'votes' => array_map('intval', $validated['votes']),
            'rejected_votes' => $validated['rejected_votes'] ?? null,
            'source' => 'manual',
            'note' => $validated['note'] ?? null,
            'entered_by' => $request->user()->name,
        ]);

        $area = $ward !== '' ? "{$ward}, {$lga}" : $lga;
        Audit::record('official.collation', "Entered the declared {$collation->form()} for {$area}: ".json_encode($collation->votesByParty()));

        return redirect()->route('compare.lga', $lga)->with('status', "Saved the {$collation->form()} for {$area}.");
    }

    /**
     * @return array{0: string, 1: string} level and ward ('' for an LGA)
     */
    private function area(string $level, string $lga, ?string $ward): array
    {
        abort_unless(in_array($level, [OfficialCollation::WARD, OfficialCollation::LGA], true), 404);
        $ward = $level === OfficialCollation::WARD ? (string) $ward : '';
        abort_if($level === OfficialCollation::WARD && $ward === '', 404);

        abort_unless(PollingUnit::query()->where('lga', $lga)->when($ward !== '', fn ($query) => $query->where('ward', $ward))->exists(), 404);

        return [$level, $ward];
    }
}
