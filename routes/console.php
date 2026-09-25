<?php

use Illuminate\Support\Facades\Schedule;

// Where the host allows a per-minute cron (`php artisan schedule:run`),
// it drives the same runner as the pinger and web requests. Hosts that
// forbid it use the pinger URL on the System page instead (see
// App\Support\BackgroundRunner and docs/DEPLOY-SHARED-HOSTING.md).
Schedule::command('app:tick')->everyMinute()->withoutOverlapping(5);
