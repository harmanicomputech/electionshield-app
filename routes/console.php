<?php

use Illuminate\Support\Facades\Schedule;

// One cron job runs `php artisan schedule:run` every minute (see README).
// A command rather than a closure, so it also proves the host lets the
// scheduler start processes.
Schedule::command('app:heartbeat')->everyMinute();

// Safety net: catch anything a webhook missed.
Schedule::command('ussd:sync')->everyThreeMinutes()->withoutOverlapping(10);
