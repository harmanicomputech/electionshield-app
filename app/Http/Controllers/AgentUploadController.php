<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Console\PhotoController;
use App\Models\Ec8aPhoto;
use App\Models\Result;
use App\Services\Ec8aPhotoStore;
use App\Support\UploadLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The agent's no-login upload page: /u/{reference}/{token}. The token (see
 * UploadLink) is the authorisation, so the page shows nothing beyond the
 * PU name and how many photos are in; the result may not have reached
 * this app yet when the agent opens it.
 */
class AgentUploadController extends Controller
{
    public function show(string $reference, string $token): View
    {
        abort_unless(UploadLink::valid($reference, $token), 404);

        return view('upload.agent', [
            'reference' => $reference,
            'token' => $token,
            'unit' => Result::query()->where('reference', $reference)->with('pollingUnit:code,name')->first()?->pollingUnit,
            'count' => Ec8aPhoto::query()->where('result_reference', $reference)->count(),
            'max' => Ec8aPhotoStore::MAX_PER_RESULT,
        ]);
    }

    public function store(Request $request, Ec8aPhotoStore $store, string $reference, string $token): RedirectResponse|JsonResponse
    {
        abort_unless(UploadLink::valid($reference, $token), 404);

        $request->validate([
            'photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
        ]);

        $sha = hash_file('sha256', $request->file('photo')->getRealPath());
        $duplicate = Ec8aPhoto::query()->where(['result_reference' => $reference, 'sha256' => $sha])->exists();

        if (! $duplicate && PhotoController::tooMany($reference)) {
            $message = 'This result already has '.Ec8aPhotoStore::MAX_PER_RESULT.' photos. Thank you.';

            return $request->expectsJson() ? response()->json(['message' => $message], 422) : back()->with('error', $message);
        }

        $store->store($request->file('photo'), $reference, 'agent', null);

        return $request->expectsJson()
            ? response()->json(['status' => 'stored'])
            : back()->with('status', 'Photo received. Thank you.');
    }
}
