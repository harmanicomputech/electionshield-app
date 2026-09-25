<?php

namespace App\Console\Commands;

use App\Support\BackgroundRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:tick')]
#[Description('Run the background work once (for the host cron, at any interval)')]
class Tick extends Command
{
    public function handle(BackgroundRunner $runner): void
    {
        $this->line($runner->run(queueSeconds: 50, source: 'cron') ? 'Background work done.' : 'Another run is in progress.');
    }
}
