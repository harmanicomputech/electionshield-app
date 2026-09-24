<?php

namespace App\Console\Commands;

use App\Models\Broadcast;
use App\Services\Broadcasting\BroadcastDispatcher;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('broadcast:dispatch')]
#[Description('Start broadcasts whose scheduled time has come')]
class DispatchScheduledBroadcasts extends Command
{
    public function handle(BroadcastDispatcher $dispatcher): void
    {
        Broadcast::query()->where('status', Broadcast::SCHEDULED)->where('scheduled_at', '<=', now())->each(function (Broadcast $broadcast) use ($dispatcher) {
            $count = $dispatcher->start($broadcast);
            Audit::record('broadcast.sent', "Sent scheduled broadcast \"{$broadcast->title}\" to {$count} recipients", actor: 'Scheduler');
            $this->line("{$broadcast->title}: {$count} recipients");
        });
    }
}
