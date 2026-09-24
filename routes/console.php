<?php

use Illuminate\Support\Facades\Schedule;

// One cron job runs `php artisan schedule:run` every minute (see README).
// A command rather than a closure, so it also proves the host lets the
// scheduler start processes.
Schedule::command('app:heartbeat')->everyMinute();

// Safety net: catch anything a webhook missed.
Schedule::command('ussd:sync')->everyThreeMinutes()->withoutOverlapping(10);

// Broadcasts scheduled for now.
Schedule::command('broadcast:dispatch')->everyMinute()->withoutOverlapping(5);

// Shared hosting has no permanent queue worker: work the queue (broadcast
// batches) for most of each minute from the scheduler. Must stay last.
if (config('election.scheduler_runs_queue')) {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=5')
        ->everyMinute()
        ->withoutOverlapping(2);
}
