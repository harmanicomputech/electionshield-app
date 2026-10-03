<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Ec8aPhoto;
use App\Models\Result;
use App\Models\ResultCheck;
use App\Services\Ai\Claude;
use App\Services\Ai\ResultChecks;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Results the automatic checks flag (rules and the AI reading of the EC8A
 * photo), most serious first. A person looks at each and marks it reviewed.
 */
class ResultCheckController extends Controller
{
    public function index(Request $request, ResultChecks $checks, Claude $ai): View
    {
        $rehearsal = Settings::showingRehearsal();
        $reviewed = $request->query('show') === 'reviewed';
        $rows = $checks->flagged($rehearsal, $reviewed);
        $photos = Ec8aPhoto::query()->whereIn('result_reference', $rows->pluck('result.reference'))->latest('id')->get()->unique('result_reference')->keyBy('result_reference');

        return view('result-checks.index', [
            'rows' => $rows,
            'photos' => $photos,
            'reviewed' => $reviewed,
            'counts' => [
                'open' => $reviewed ? $checks->flagged($rehearsal)->count() : $rows->count(),
                'reviewed' => $reviewed ? $rows->count() : $checks->flagged($rehearsal, true)->count(),
            ],
            'aiOn' => $ai->enabled(),
            'photosRead' => ResultCheck::query()->whereNotNull('photo_checked_at')->count(),
            'photosWaiting' => max(0, Ec8aPhoto::query()->distinct()->count('result_reference') - ResultCheck::query()->whereNotNull('photo_checked_at')->count()),
        ]);
    }

    public function review(Request $request, string $reference): RedirectResponse|JsonResponse
    {
        $result = Result::query()->where('reference', $reference)->firstOrFail();
        $validated = $request->validate(['reviewed' => ['required', 'boolean'], 'review_note' => ['nullable', 'string', 'max:500']]);
        $check = ResultCheck::query()->firstOrNew(['result_reference' => $result->reference]);

        $check->fill($validated['reviewed']
            ? ['reviewed_at' => $check->reviewed_at ?? now(), 'reviewed_by' => $check->reviewed_by ?? $request->user()->name, 'review_note' => $validated['review_note'] ?? $check->review_note]
            : ['reviewed_at' => null, 'reviewed_by' => null])->save();
        Audit::record('result_check.reviewed', ($validated['reviewed'] ? 'Reviewed the flags on ' : 'Reopened the flags on ').$result->reference, details: array_filter(['note' => $validated['review_note'] ?? null]));

        $message = $validated['reviewed'] ? "Marked {$result->reference} reviewed." : "{$result->reference} is back in the list.";

        return $request->expectsJson() ? response()->json(['message' => $message]) : back()->with('status', $message);
    }

    /**
     * Read (again) the latest EC8A photo of a result with AI.
     */
    public function photo(string $reference, ResultChecks $checks): RedirectResponse
    {
        $result = Result::query()->with('votes')->where('reference', $reference)->firstOrFail();
        $photo = Ec8aPhoto::query()->where('result_reference', $reference)->latest('id')->firstOrFail();

        $check = $checks->readPhoto($result, $photo);

        return back()->with($check->photo_error ? 'error' : 'status', $check->photo_error
            ? 'The photo could not be read: '.$check->photo_error
            : match ($check->photo_status) {
                ResultCheck::PHOTO_MATCHES => "The EC8A photo of {$reference} matches the agent's figures.",
                ResultCheck::PHOTO_MISMATCH => "The EC8A photo of {$reference} shows different figures.",
                default => "The EC8A photo of {$reference} could not be read.",
            });
    }
}
