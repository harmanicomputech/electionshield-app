<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Models\BroadcastMessage;
use App\Services\Broadcasting\BroadcastDispatcher;
use App\Services\Broadcasting\SmsSender;
use App\Services\Broadcasting\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends up to BroadcastDispatcher::BATCH queued messages of a broadcast.
 * Only messages still "queued" are sent, so a retry never double-sends.
 */
class SendBroadcastBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 60, 300, 900];

    /**
     * @param  list<int>  $messageIds
     */
    public function __construct(public int $broadcastId, public array $messageIds) {}

    public function handle(SmsSender $sms, WhatsAppSender $whatsapp, BroadcastDispatcher $dispatcher): void
    {
        $broadcast = Broadcast::query()->find($this->broadcastId);

        if (! $broadcast || $broadcast->status !== Broadcast::SENDING) {
            return;
        }

        $messages = BroadcastMessage::query()->whereIn('id', $this->messageIds)->where('status', 'queued')->get();

        if ($messages->isNotEmpty()) {
            ($broadcast->channel === 'whatsapp' ? $whatsapp : $sms)->send($broadcast, $messages);
        }

        $dispatcher->finishIfDone($broadcast->refresh());
    }

    public function failed(): void
    {
        BroadcastMessage::query()->whereIn('id', $this->messageIds)->where('status', 'queued')->update(['status' => 'failed', 'failure_reason' => 'Could not reach the provider', 'updated_at' => now()]);

        if ($broadcast = Broadcast::query()->find($this->broadcastId)) {
            app(BroadcastDispatcher::class)->finishIfDone($broadcast);
        }
    }
}
