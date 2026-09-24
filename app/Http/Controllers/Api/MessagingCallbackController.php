<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BroadcastMessage;
use App\Models\OptOut;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Callbacks from the messaging providers:
 *  - Africa's Talking delivery reports and bulk-SMS opt-outs, at
 *    /api/sms/delivery/{secret} and /api/sms/opt-out/{secret};
 *  - the WhatsApp Cloud API webhook at /api/whatsapp (statuses, and
 *    "STOP" replies as opt-outs), signed with the app secret.
 */
class MessagingCallbackController extends Controller
{
    public function smsDelivery(Request $request, string $secret): JsonResponse
    {
        $this->checkSecret($secret);

        $message = BroadcastMessage::query()->where('provider_id', (string) $request->input('id'))->first();

        if ($message) {
            $status = (string) $request->input('status');

            match ($status) {
                'Success' => $message->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'failure_reason' => null]),
                'Failed', 'Rejected', 'AbsentSubscriber', 'Expired' => $message->forceFill(['status' => 'failed', 'failure_reason' => Str::limit($request->input('failureReason') ?: $status, 250)]),
                default => null, // Sent, Submitted, Buffered: still on its way
            };

            $message->save();
        }

        return response()->json(['status' => 'ok']);
    }

    public function smsOptOut(Request $request, string $secret): JsonResponse
    {
        $this->checkSecret($secret);

        if ($phone = Phone::normalize((string) $request->input('phoneNumber'))) {
            OptOut::record($phone, 'sms', 'africastalking');
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Meta's subscription check: echo the challenge if the token matches.
     */
    public function whatsappVerify(Request $request): Response
    {
        $token = (string) config('services.whatsapp.verify_token');

        abort_unless($token !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    public function whatsapp(Request $request): JsonResponse
    {
        $secret = (string) config('services.whatsapp.app_secret');
        $signature = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        abort_unless($secret !== '' && hash_equals($signature, (string) $request->header('X-Hub-Signature-256')), 401);

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $message = BroadcastMessage::query()->where('provider_id', (string) ($status['id'] ?? ''))->first();

                    match ($status['status'] ?? null) {
                        'delivered', 'read' => $message?->forceFill(['status' => 'delivered', 'delivered_at' => $message->delivered_at ?? now()])->save(),
                        'failed' => $message?->forceFill(['status' => 'failed', 'failure_reason' => Str::limit((string) ($status['errors'][0]['title'] ?? 'Failed'), 250)])->save(),
                        default => null,
                    };
                }

                foreach ((array) ($value['messages'] ?? []) as $incoming) {
                    $text = strtoupper(trim((string) ($incoming['text']['body'] ?? '')));

                    if (in_array($text, ['STOP', 'UNSUBSCRIBE', 'STOP ALL'], true) && ($phone = Phone::normalize((string) ($incoming['from'] ?? '')))) {
                        OptOut::record($phone, 'whatsapp', 'whatsapp reply');
                    }
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    private function checkSecret(string $secret): void
    {
        $expected = (string) config('services.africastalking.callback_secret');

        abort_unless($expected !== '' && hash_equals($expected, $secret), 404);
    }
}
