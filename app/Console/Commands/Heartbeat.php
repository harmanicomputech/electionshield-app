<?php

namespace App\Console\Commands;

use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:heartbeat')]
#[Description('Record that the scheduler cron ran (shown on the System page)')]
class Heartbeat extends Command
{
    public function handle(): void
    {
        Settings::set('scheduler_heartbeat', now()->toIso8601String());
    }
}
