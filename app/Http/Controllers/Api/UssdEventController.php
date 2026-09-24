<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\UssdIngestor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * POST /api/ussd-events: one event from the USSD service. We answer 2xx
 * only once the event is stored and applied; anything else is retried.
 */
class UssdEventController extends Controller
{
    public function __invoke(Request $request, UssdIngestor $ingestor): JsonResponse
    {
        $validated = $request->validate([
            'event' => ['required', 'string', 'max:60'],
            'data' => ['required', 'array'],
        ]);

        $key = Str::limit((string) ($request->header('Idempotency-Key') ?: $validated['event'].':'.hash('sha256', $request->getContent())), 250, '');

        $event = WebhookEvent::query()->where('idempotency_key', $key)->first();

        if ($event?->processed_at !== null) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            $event ??= WebhookEvent::create([
                'idempotency_key' => $key,
                'event' => $validated['event'],
                'payload' => $request->json()->all(),
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // The same event arriving twice at once: the other request applies it.
            return response()->json(['status' => 'duplicate']);
        }

        try {
            $known = DB::transaction(fn () => $ingestor->applyEvent($validated['event'], $validated['data']));
        } catch (Throwable $e) {
            report($e);
            $event->forceFill(['error' => Str::limit($e->getMessage(), 1000)])->save();

            return response()->json(['message' => 'The event could not be applied.'], 500);
        }

        $event->forceFill(['processed_at' => now(), 'error' => $known ? null : 'Unknown event type; stored only.'])->save();

        return response()->json(['status' => $known ? 'stored' : 'ignored']);
    }
}
