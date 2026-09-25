<?php

namespace App\Support;

use App\Services\UssdSync;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs the background work (the catch-up sync, scheduled broadcasts and the
 * queue of broadcast batches) without a per-minute cron, which many shared
 * hosts (DomainKing among them) forbid. The same design as the USSD
 * service. It is triggered from three places, all safe to overlap:
 *
 *  - after the response of ordinary web requests (RunBackgroundWork);
 *  - an external pinger opening /cron/{token} every minute (cron-job.org);
 *  - the host's cron running `php artisan app:tick` (hourly is fine), or
 *    `schedule:run` every minute where that is allowed.
 *
 * Periodic tasks remember the slot they last ran for, so nothing runs twice.
 */
class BackgroundRunner
{
    /** Don't start a run from web requests more often than this. */
    private const MIN_SECONDS_BETWEEN_WEB_RUNS = 15;

    /** The catch-up sync runs once per slot of this many minutes. */
    private const SYNC_EVERY_MINUTES = 3;

    public static function token(): string
    {
        return substr(hash_hmac('sha256', 'election-shield-web-runner', (string) config('app.key')), 0, 32);
    }

    public static function enabled(): bool
    {
        return (bool) config('election.background_runner');
    }

    /**
     * Whether PHP can send the response before carrying on (PHP-FPM or
     * LiteSpeed); otherwise the visitor would wait for the work.
     */
    public static function canWorkAfterResponse(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    public function runAfterWebRequest(): void
    {
        if (! self::enabled() || ! Cache::add('election-shield-web:runner-throttle', true, self::MIN_SECONDS_BETWEEN_WEB_RUNS)) {
            return;
        }

        $this->run(queueSeconds: 20, source: 'web');
    }

    /**
     * One pass: due tasks, then the queue for up to $queueSeconds. Only one
     * pass runs at a time; returns false if another is running.
     */
    public function run(int $queueSeconds = 20, string $source = 'cron'): bool
    {
        $lock = Cache::lock('election-shield-web:runner', $queueSeconds + 120);

        if (! $lock->get()) {
            return false;
        }

        try {
            @set_time_limit($queueSeconds + 100);
            ignore_user_abort(true);

            Settings::set('scheduler_heartbeat', now()->toIso8601String());
            Settings::set('runner_source', $source);

            $this->runDueTasks();

            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => $queueSeconds,
                '--tries' => 5,
            ]);
        } catch (Throwable $e) {
            report($e);
        } finally {
            $lock->release();
        }

        return true;
    }

    private function runDueTasks(): void
    {
        $now = now();

        // Scheduled broadcasts: checks for due ones, safe every run.
        $this->attempt(fn () => Artisan::call('broadcast:dispatch'));

        // The read-API catch-up sync, once per 3-minute slot.
        if (app(UssdSync::class)->enabled()) {
            $slot = $now->format('Y-m-d H:').str_pad((string) (intdiv((int) $now->format('i'), self::SYNC_EVERY_MINUTES) * self::SYNC_EVERY_MINUTES), 2, '0', STR_PAD_LEFT);

            if (Settings::get('runner.ussd-sync') !== $slot) {
                Settings::set('runner.ussd-sync', $slot);
                $this->attempt(fn () => Artisan::call('ussd:sync'));
            }
        }
    }

    private function attempt(callable $task): void
    {
        try {
            $task();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
