<?php

namespace App\Services\Broadcasting;

use App\Jobs\SendBroadcastBatch;
use App\Models\Broadcast;
use App\Models\BroadcastMessage;
use Illuminate\Support\Facades\DB;

/**
 * Starts a broadcast: freezes its recipient list (so a contact added
 * mid-send doesn't change it) and queues it in batches.
 */
class BroadcastDispatcher
{
    public const BATCH = 100;

    public function __construct(private Audience $audience) {}

    public function start(Broadcast $broadcast, ?string $by = null): int
    {
        $count = DB::transaction(function () use ($broadcast, $by) {
            $broadcast = Broadcast::query()->lockForUpdate()->findOrFail($broadcast->id);

            if (! $broadcast->editable()) {
                return null;
            }

            $now = now();
            $rows = $this->audience->recipients($broadcast->audience, $broadcast->channel)
                ->map(fn (array $person) => ['broadcast_id' => $broadcast->id, 'phone' => $person['phone'], 'name' => $person['name'], 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now])
                ->values();

            $rows->chunk(500)->each(fn ($chunk) => BroadcastMessage::query()->insertOrIgnore($chunk->all()));

            $broadcast->forceFill(['status' => Broadcast::SENDING, 'started_at' => $now, 'sent_by' => $by ?? $broadcast->created_by])->save();

            return $rows->count();
        });

        if ($count === null) {
            return 0;
        }

        $broadcast->messages()->where('status', 'queued')->orderBy('id')->pluck('id')
            ->chunk(self::BATCH)
            ->each(fn ($ids) => SendBroadcastBatch::dispatch($broadcast->id, $ids->values()->all()));

        // Reload: the status was changed on the locked copy above. With no
        // recipients (or every batch already sent) it is finished now.
        $this->finishIfDone($broadcast->refresh());

        return $count;
    }

    public function finishIfDone(Broadcast $broadcast): void
    {
        if ($broadcast->status === Broadcast::SENDING && ! $broadcast->messages()->where('status', 'queued')->exists()) {
            $broadcast->forceFill(['status' => Broadcast::SENT, 'finished_at' => now()])->save();
        }
    }
}
