<?php

namespace App\Console\Commands;

use App\Services\Irev\IrevWatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('irev:watch {--wards=15 : Wards to look at} {--sheets=6 : Sheets to fetch and read}')]
#[Description('Look for new EC8A uploads on IReV for the followed election and read them')]
class WatchIrev extends Command
{
    public function handle(IrevWatcher $watcher): int
    {
        if (! $watcher->configured()) {
            $this->error('Choose the election to follow first (Official vs PVT → IReV).');

            return self::FAILURE;
        }

        $done = $watcher->step((int) $this->option('wards'), (int) $this->option('sheets'));
        $this->line("Looked at {$done['wards']} wards and {$done['sheets']} sheets.");

        return self::SUCCESS;
    }
}
