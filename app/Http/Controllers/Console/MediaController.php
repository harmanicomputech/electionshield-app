<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Incident;
use App\Models\MaterialReport;
use App\Models\Result;
use App\Services\FieldMedia;
use App\Support\Audit;
use App\Support\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Photos and videos agents attach to incidents and results (EC8A sheet
 * photos have their own page). Files are private: the situation room sees
 * them all; an agent sees their own.
 */
class MediaController extends Controller
{
    public const CHECKED = 'checked';

    public const DOUBTFUL = 'doubtful';

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(['all', Attachment::IMAGE, Attachment::VIDEO, 'materials'])],
            'reference' => ['nullable', 'string', 'max:20'],
        ]);
        $kind = $filters['kind'] ?? 'all';
        $items = Attachment::query()
            ->when(in_array($kind, [Attachment::IMAGE, Attachment::VIDEO], true), fn ($query) => $query->where('kind', $kind))
            ->when($kind === 'materials', fn ($query) => $query->where('reference', 'like', 'MAT-%'))
            ->when($filters['reference'] ?? null, fn ($query, $reference) => $query->where('reference', $reference))
            ->latest()->simplePaginate(24)->withQueryString();
        $references = $items->pluck('reference');
        $materialIds = $references->filter(fn ($reference) => str_starts_with($reference, 'MAT-'))->map(fn ($reference) => (int) substr($reference, 4));

        return view('media.index', [
            'kind' => $kind,
            'items' => $items,
            'counts' => Attachment::query()->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind'),
            'incidents' => Incident::query()->whereIn('reference', $references)->get()->keyBy('reference'),
            'results' => Result::query()->whereIn('reference', $references)->get()->keyBy('reference'),
            'materials' => MaterialReport::query()->whereIn('ussd_id', $materialIds)->get()->keyBy(fn (MaterialReport $report) => $report->reference()),
            'materialsCount' => Attachment::query()->where('reference', 'like', 'MAT-%')->count(),
            'reference' => $filters['reference'] ?? null,
        ]);
    }

    public function file(Request $request, Attachment $attachment): BinaryFileResponse
    {
        $user = $request->user();
        $own = filled($user->phone) && (
            Incident::query()->where(['reference' => $attachment->reference, 'agent_phone' => $user->phone])->exists()
            || Result::query()->where(['reference' => $attachment->reference, 'agent_phone' => $user->phone])->exists()
            || (str_starts_with($attachment->reference, 'MAT-') && MaterialReport::query()->where(['ussd_id' => (int) substr($attachment->reference, 4), 'agent_phone' => $user->phone])->exists())
        );
        abort_unless($own || $user->can(Permission::VIEW_DASHBOARDS), 403);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        // A file response answers Range requests, so videos can be scrubbed.
        return response()->file(Storage::disk('local')->path($attachment->path), [
            'Content-Type' => $attachment->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function review(Request $request, Attachment $attachment): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'review_status' => ['required', Rule::in([self::CHECKED, self::DOUBTFUL])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $attachment->forceFill([...$validated, 'reviewed_by' => $request->user()->name, 'reviewed_at' => now()])->save();
        Audit::record('media.reviewed', "Marked a {$attachment->kind} for {$attachment->reference} as {$validated['review_status']}");

        return $request->expectsJson() ? response()->json(['status' => $attachment->review_status]) : back()->with('status', 'Saved.');
    }

    public function destroy(Attachment $attachment, FieldMedia $media): RedirectResponse
    {
        $media->delete($attachment);
        Audit::record('media.deleted', "Deleted a {$attachment->kind} ({$attachment->sizeLabel()}, sha256 {$attachment->sha256}) from {$attachment->reference}");

        return back()->with('status', 'Deleted.');
    }
}
