<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\PushNotifier;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Per-device opt-in to Web Push notifications.
 */
class PushController extends Controller
{
    public function show(Request $request, PushNotifier $notifier): View
    {
        return view('push.show', [
            'configured' => $notifier->configured(),
            'devices' => $request->user()->pushSubscriptions()->latest()->get(),
            'topics' => PushSubscription::TOPICS,
        ]);
    }

    public function subscribe(Request $request, PushNotifier $notifier): JsonResponse
    {
        abort_unless($notifier->configured(), 503, 'Notifications are not set up yet. An admin can set them up on the System page.');

        $validated = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aesgcm,aes128gcm'],
            'topics' => ['required', 'array', 'min:1'],
            'topics.*' => [Rule::in(array_keys(PushSubscription::TOPICS))],
        ]);

        $subscription = PushSubscription::updateOrCreate(['endpoint_hash' => PushSubscription::hashEndpoint($validated['endpoint'])], [
            'user_id' => $request->user()->id,
            'endpoint' => $validated['endpoint'],
            'public_key' => $validated['keys']['p256dh'],
            'auth_token' => $validated['keys']['auth'],
            'content_encoding' => $validated['contentEncoding'] ?? 'aes128gcm',
            'topics' => array_values(array_unique($validated['topics'])),
            'device' => Str::limit((string) $request->userAgent(), 250),
        ]);

        if ($subscription->wasRecentlyCreated) {
            Audit::record('push.subscribed', 'Turned on notifications on a device ('.implode(', ', $subscription->topics).')');
        }

        return response()->json(['status' => 'subscribed', 'topics' => $subscription->topics]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $endpoint = $request->validate(['endpoint' => ['required', 'string', 'max:2000']])['endpoint'];

        $deleted = $request->user()->pushSubscriptions()->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->delete();

        if ($deleted) {
            Audit::record('push.unsubscribed', 'Turned off notifications on a device');
        }

        return response()->json(['status' => 'unsubscribed']);
    }

    public function test(Request $request, PushNotifier $notifier): JsonResponse
    {
        $sent = $notifier->send($request->user()->pushSubscriptions()->get()->all(), [
            'title' => 'Election Shield test',
            'body' => 'Notifications work on this device.',
            'url' => route('push', absolute: false),
            'tag' => 'test',
        ]);

        return response()->json(['sent' => $sent], $sent ? 200 : 422);
    }
}
