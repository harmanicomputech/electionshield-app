<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\AlertSnooze;
use App\Models\Result;
use App\Services\AlertFeed;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The situation-room pop-ups (app.js asks for them every few seconds).
 */
class AlertController extends Controller
{
    public function index(Request $request, AlertFeed $feed): JsonResponse
    {
        return response()->json([
            'items' => $feed->pending($request->user()),
            'repeat_minutes' => (int) config('election.alerts.repeat_minutes'),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * "Remind me later" (or a pop-up closed without an answer, which comes
     * back after the repeat interval).
     */
    public function snooze(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:20'],
            'minutes' => ['required', 'integer', Rule::in([5, 10, 15, 30, 60, (int) config('election.alerts.repeat_minutes')])],
        ]);

        AlertSnooze::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'reference' => $validated['reference']],
            ['until' => now()->addMinutes((int) $validated['minutes'])],
        );

        return response()->json(['status' => 'snoozed', 'until' => now()->addMinutes((int) $validated['minutes'])->toIso8601String()]);
    }

    public function acknowledgeResult(Request $request, string $reference): JsonResponse|RedirectResponse
    {
        $result = Result::query()->where('reference', $reference)->firstOrFail();

        if ($result->acknowledged_at === null) {
            $result->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()->name])->save();
            Audit::record('result.acknowledged', "Acknowledged result {$result->reference} for PU {$result->polling_unit_code}");
        }

        $message = "Acknowledged {$result->reference}.";

        return $request->expectsJson() ? response()->json(['status' => 'acknowledged', 'message' => $message]) : back()->with('status', $message);
    }
}
