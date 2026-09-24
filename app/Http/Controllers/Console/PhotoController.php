<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Ec8aPhoto;
use App\Models\Result;
use App\Services\Ec8aPhotoStore;
use App\Support\Audit;
use App\Support\UploadLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * EC8A photos: upload (tied to a result reference), view next to our
 * figures, and mark whether the sheet matches them.
 */
class PhotoController extends Controller
{
    public function __construct(private Ec8aPhotoStore $store) {}

    public function index(Request $request): View
    {
        $status = $request->validate(['status' => ['nullable', Rule::in([Ec8aPhoto::UNCHECKED, Ec8aPhoto::MATCHES, Ec8aPhoto::MISMATCH, 'all'])]])['status'] ?? Ec8aPhoto::UNCHECKED;

        return view('photos.index', [
            'status' => $status,
            'photos' => Ec8aPhoto::query()
                ->with('result.pollingUnit:code,name')
                ->when($status !== 'all', fn ($query) => $query->where('review_status', $status))
                ->latest()
                ->simplePaginate(24)
                ->withQueryString(),
            'counts' => Ec8aPhoto::query()->selectRaw('review_status, count(*) as total')->groupBy('review_status')->pluck('total', 'review_status'),
            'reference' => $request->query('reference'),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $request->merge(['reference' => strtoupper(trim((string) $request->input('reference')))]);

        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:20', Rule::exists('results', 'reference')],
            'photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['reference.exists' => 'No result with that reference.']);

        return $this->save($request, $validated['reference'], 'coordinator', $request->user()->name, $validated['note'] ?? null);
    }

    public function show(Ec8aPhoto $photo): View
    {
        $photo->load('result.pollingUnit', 'result.votes');

        return view('photos.show', [
            'photo' => $photo,
            'others' => Ec8aPhoto::query()->where('result_reference', $photo->result_reference)->whereKeyNot($photo->id)->get(),
            'uploadLink' => UploadLink::url($photo->result_reference),
        ]);
    }

    public function image(Ec8aPhoto $photo, ?string $size = null): Response
    {
        $path = $size === 'thumb' && $photo->thumbnail_path ? $photo->thumbnail_path : $photo->path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            // Private: never kept by shared caches, and not by the service worker.
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function review(Request $request, Ec8aPhoto $photo): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'review_status' => ['required', Rule::in([Ec8aPhoto::MATCHES, Ec8aPhoto::MISMATCH])],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $changed = $photo->review_status !== $validated['review_status'] || $photo->review_note !== ($validated['review_note'] ?? null);

        if ($changed) {
            $photo->forceFill([...$validated, 'reviewed_by' => $request->user()->name, 'reviewed_at' => now()])->save();
            Audit::record('photo.reviewed', "Marked the EC8A photo for {$photo->result_reference} as {$photo->review_status}".($photo->review_note ? ": {$photo->review_note}" : ''));
        }

        return $request->expectsJson()
            ? response()->json(['status' => $photo->review_status])
            : back()->with('status', 'Saved: '.$photo->reviewLabel().'.');
    }

    public function destroy(Ec8aPhoto $photo): RedirectResponse
    {
        $this->store->delete($photo);
        Audit::record('photo.deleted', "Deleted an EC8A photo for {$photo->result_reference} (sha256 {$photo->sha256})");

        return redirect()->route('photos')->with('status', "Deleted the photo for {$photo->result_reference}.");
    }

    /**
     * Shared with the agent upload page.
     */
    public static function tooMany(string $reference): bool
    {
        return Ec8aPhoto::query()->where('result_reference', $reference)->count() >= Ec8aPhotoStore::MAX_PER_RESULT;
    }

    private function save(Request $request, string $reference, string $via, ?string $by, ?string $note): RedirectResponse|JsonResponse
    {
        $sha = hash_file('sha256', $request->file('photo')->getRealPath());
        $duplicate = Ec8aPhoto::query()->where(['result_reference' => $reference, 'sha256' => $sha])->exists();

        if (! $duplicate && self::tooMany($reference)) {
            $message = 'This result already has '.Ec8aPhotoStore::MAX_PER_RESULT.' photos.';

            return $request->expectsJson() ? response()->json(['message' => $message], 422) : back()->with('error', $message);
        }

        $photo = $this->store->store($request->file('photo'), $reference, $via, $by, $note);

        if ($photo->wasRecentlyCreated) {
            Audit::record('photo.uploaded', "Uploaded an EC8A photo for {$reference} (sha256 {$photo->sha256})");
        }

        return $request->expectsJson()
            ? response()->json(['status' => 'stored', 'id' => $photo->id, 'url' => route('photos.show', $photo)])
            : redirect()->route('photos.show', $photo)->with('status', 'Photo uploaded.');
    }
}
