<?php

namespace App\Http\Controllers\Console;

use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Models\Result;
use App\Services\UssdApi;
use App\Services\UssdIngestor;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Reviewing corrections agents asked for. The decision is made in the USSD
 * service (the source of truth) through its API; our copy is updated from
 * its answer straight away, and again by the webhook that follows.
 */
class CorrectionController extends Controller
{
    public function index(UssdApi $api): View
    {
        $rehearsal = Settings::showingRehearsal();
        $base = Result::query()->whereNotNull('corrects_reference')->where('rehearsal', $rehearsal)->with('votes', 'pollingUnit:code,name');

        $pending = (clone $base)->where('status', ResultStatus::Pending)->orderBy('submitted_at')->get();
        $originals = Result::query()->with('votes')->whereIn('reference', $pending->pluck('corrects_reference'))->get()->keyBy('reference');

        return view('corrections.index', [
            'pending' => $pending,
            'originals' => $originals,
            'recent' => (clone $base)->whereIn('status', [ResultStatus::Accepted, ResultStatus::Rejected, ResultStatus::Superseded])->whereNotNull('reviewed_at')->latest('reviewed_at')->limit(20)->get(),
            'connected' => $api->enabled(),
        ]);
    }

    public function approve(Request $request, string $reference, UssdApi $api, UssdIngestor $ingestor): RedirectResponse|JsonResponse
    {
        return $this->decide($request, $reference, 'approve', $api, $ingestor);
    }

    public function reject(Request $request, string $reference, UssdApi $api, UssdIngestor $ingestor): RedirectResponse|JsonResponse
    {
        return $this->decide($request, $reference, 'reject', $api, $ingestor);
    }

    private function decide(Request $request, string $reference, string $decision, UssdApi $api, UssdIngestor $ingestor): RedirectResponse|JsonResponse
    {
        $note = $request->validate(['note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:255']], ['note.required' => 'Say why the correction is rejected.'])['note'] ?? null;
        $correction = Result::query()->where('reference', $reference)->whereNotNull('corrects_reference')->firstOrFail();
        $wanted = $decision === 'approve' ? ResultStatus::Accepted : ResultStatus::Rejected;

        try {
            $response = $api->post("/corrections/{$reference}/{$decision}", array_filter(['reviewed_by' => $request->user()->name, 'note' => $note]));

            if ($response->status() === 409) {
                // Already decided (in the USSD console, or this is a replay):
                // bring our copy up to date and say what happened.
                $current = $api->get("/results/{$reference}")->throw()->json('data');
                $this->apply($ingestor, $current, $correction);
                $done = ($current['status'] ?? null) === $wanted->value;

                return $this->answer($request, $done, $done ? "{$reference} was already ".($decision === 'approve' ? 'approved' : 'rejected').'.' : "{$reference} was already reviewed: ".($current['status'] ?? 'unknown').'.', $done ? 200 : 409);
            }

            $data = $response->throw()->json('data');
        } catch (Throwable $e) {
            report($e);

            // A 5xx makes the offline queue keep the action and retry.
            return $this->answer($request, false, 'Could not reach the USSD service: '.Str::limit($e->getMessage(), 150), 502);
        }

        $this->apply($ingestor, $data, $correction);
        Audit::record('correction.'.($decision === 'approve' ? 'approved' : 'rejected'), ucfirst($decision === 'approve' ? 'approved' : 'rejected')." correction {$reference} (corrects {$correction->corrects_reference}) at PU {$correction->polling_unit_code}".($note ? ": {$note}" : ''));

        return $this->answer($request, true, $decision === 'approve' ? "Approved {$reference}: it replaces {$correction->corrects_reference}." : "Rejected {$reference}; {$correction->corrects_reference} still counts.");
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function apply(UssdIngestor $ingestor, ?array $data, Result $correction): void
    {
        if (! is_array($data) || ! isset($data['reference'])) {
            return;
        }

        $supersedes = ($data['status'] ?? null) === ResultStatus::Accepted->value ? $correction->corrects_reference : null;
        $ingestor->result($data, $correction->rehearsal, $supersedes);
    }

    private function answer(Request $request, bool $ok, string $message, int $status = 200): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $ok ? 200 : $status);
        }

        return back()->with($ok ? 'status' : 'error', $message);
    }
}
