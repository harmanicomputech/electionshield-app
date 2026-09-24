<?php

namespace App\Services\Broadcasting;

use App\Models\Broadcast;
use App\Models\BroadcastMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Africa's Talking bulk SMS: one request per batch of recipients, each
 * reported back with its own status and message id (used to match the
 * delivery reports later).
 */
class SmsSender
{
    private const LIVE_URL = 'https://api.africastalking.com/version1/messaging';

    private const SANDBOX_URL = 'https://api.sandbox.africastalking.com/version1/messaging';

    /** Africa's Talking recipient codes for an accepted message. */
    private const ACCEPTED = [100, 101, 102];

    public function configured(): bool
    {
        return filled(config('services.africastalking.api_key'));
    }

    /**
     * @param  Collection<int, BroadcastMessage>  $messages
     */
    public function send(Broadcast $broadcast, Collection $messages): void
    {
        $config = config('services.africastalking');

        if (! $this->configured()) {
            throw new RuntimeException('Set AFRICASTALKING_API_KEY in .env to send SMS.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->withHeaders(['apiKey' => $config['api_key']])
            ->timeout(30)
            ->post($config['username'] === 'sandbox' ? self::SANDBOX_URL : self::LIVE_URL, array_filter([
                'username' => $config['username'],
                'to' => $messages->pluck('phone')->implode(','),
                'message' => $broadcast->message,
                'from' => $config['sender_id'],
                'bulkSMSMode' => 1,
                'enqueue' => 1,
            ]))
            ->throw()
            ->json('SMSMessageData.Recipients', []);

        $byPhone = collect($response)->keyBy('number');

        foreach ($messages as $message) {
            $recipient = $byPhone->get($message->phone);
            $accepted = $recipient && in_array((int) ($recipient['statusCode'] ?? 0), self::ACCEPTED, true);

            $message->forceFill([
                'status' => $accepted ? 'sent' : 'failed',
                'provider_id' => $recipient['messageId'] ?? null,
                'cost' => $recipient['cost'] ?? null,
                'failure_reason' => $accepted ? null : ($recipient['status'] ?? 'Not in the provider response'),
                'sent_at' => now(),
            ])->save();
        }
    }
}
