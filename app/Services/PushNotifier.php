<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Web Push to the devices that opted in. The VAPID keys are generated from
 * the System page (the host has no terminal) and kept in settings, unless
 * VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY are set in .env.
 *
 * Payloads carry a title, a short body and a link, never phone numbers.
 */
class PushNotifier
{
    public function publicKey(): ?string
    {
        return config('services.push.public_key') ?: Settings::get('vapid_public_key');
    }

    private function privateKey(): ?string
    {
        return config('services.push.private_key') ?: Settings::get('vapid_private_key');
    }

    public function configured(): bool
    {
        return filled($this->publicKey()) && filled($this->privateKey());
    }

    /**
     * Create the keys once. Changing them would silently break every
     * existing subscription, so this refuses when keys already exist.
     */
    public function generateKeys(): bool
    {
        if ($this->configured()) {
            return false;
        }

        $keys = VAPID::createVapidKeys();
        Settings::set('vapid_public_key', $keys['publicKey']);
        Settings::set('vapid_private_key', $keys['privateKey']);

        return true;
    }

    /**
     * Send to every device subscribed to the topic. Returns how many were delivered.
     *
     * @param  array{title: string, body: string, url: string, tag?: string, urgent?: bool}  $message
     */
    public function toTopic(string $topic, array $message): int
    {
        if (! $this->configured()) {
            return 0;
        }

        return $this->send(PushSubscription::query()->forTopic($topic)->get()->all(), $message);
    }

    /**
     * @param  list<PushSubscription>  $subscriptions
     * @param  array{title: string, body: string, url: string, tag?: string, urgent?: bool}  $message
     */
    public function send(array $subscriptions, array $message): int
    {
        if (! $this->configured() || $subscriptions === []) {
            return 0;
        }

        try {
            $push = new WebPush(
                ['VAPID' => ['subject' => config('services.push.subject') ?: 'mailto:noreply@'.parse_url((string) config('app.url'), PHP_URL_HOST), 'publicKey' => $this->publicKey(), 'privateKey' => $this->privateKey()]],
                ['TTL' => 3600, 'urgency' => ($message['urgent'] ?? false) ? 'high' : 'normal', 'topic' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', $message['tag'] ?? 'shield'), 0, 32)],
                logger: Log::channel(),
            );
        } catch (Throwable $e) {
            report($e);

            return 0;
        }

        $byEndpoint = [];

        foreach ($subscriptions as $subscription) {
            $byEndpoint[$subscription->endpoint] = $subscription;
            $push->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding,
            ]), json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $delivered = 0;

        try {
            /** @var MessageSentReport $report */
            foreach ($push->flush() as $report) {
                $subscription = $byEndpoint[$report->getEndpoint()] ?? null;

                if ($report->isSuccess()) {
                    $delivered++;
                    $subscription?->forceFill(['last_sent_at' => now()])->save();
                } elseif ($report->isSubscriptionExpired()) {
                    // The browser dropped it (uninstalled, permission revoked).
                    $subscription?->delete();
                } else {
                    Log::warning('Web Push failed: '.$report->getReason());
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $delivered;
    }
}
