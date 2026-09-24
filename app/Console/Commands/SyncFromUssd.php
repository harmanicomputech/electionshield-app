<?php

namespace App\Console\Commands;

use App\Services\UssdSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ussd:sync {--full : Re-import everything instead of only what changed}')]
#[Description('Pull records from the USSD read API (safety net for missed webhooks)')]
class SyncFromUssd extends Command
{
    public function handle(UssdSync $sync): int
    {
        if (! $sync->enabled()) {
            $this->warn('USSD_API_URL / USSD_API_TOKEN are not set; nothing to sync.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($sync->run((bool) $this->option('full')) as $resource => $row) {
            $row['error'] ? $this->error("{$resource}: {$row['count']} records, failed: {$row['error']}") : $this->line("{$resource}: {$row['count']} records");
            $failed = $failed || $row['error'] !== null;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
