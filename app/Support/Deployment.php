<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * After an upload: drop the compiled views and cached config/routes, and run
 * any database updates the new files need.
 *
 * Unzipping keeps each file's date from when the package was built, so an
 * uploaded view can look older than the server's compiled copy and Blade
 * keeps showing the old page. And new code may need a new column: until the
 * database update runs, pages that use it fail, possibly even the page an
 * admin would use to run it. Every package carries a DEPLOY_ID file; when it
 * differs from the one seen last, both are done once.
 */
class Deployment
{
    public static function refreshIfChanged(): void
    {
        try {
            $marker = base_path('DEPLOY_ID');
            if (! is_file($marker)) {
                return;
            }

            $id = trim((string) file_get_contents($marker));
            $seenFile = storage_path('framework/deploy-id');
            if ($id === '' || (is_file($seenFile) && trim((string) file_get_contents($seenFile)) === $id)) {
                return;
            }

            // One request does it; any others arriving meanwhile carry on.
            $lock = @fopen(storage_path('framework/deploy.lock'), 'c');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }

            try {
                self::clearCaches();
                self::migrate();
                @file_put_contents($seenFile, $id);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (Throwable $e) {
            // Never stop a page over this (System → database update still works).
            report($e);
        }
    }

    /**
     * Run pending database updates, only on an installed app (a fresh one
     * is set up from the first-admin page).
     */
    private static function migrate(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        if (! $schema->hasTable('migrations') || ! $schema->hasTable('users')) {
            return;
        }

        Artisan::call('migrate', ['--force' => true]);

        if (str_contains(Artisan::output(), 'DONE')) {
            Audit::record('system.migrate', 'Updated the database automatically after an upload');
        }
    }

    /**
     * @return int how many compiled views were removed
     */
    public static function clearCaches(): int
    {
        $removed = 0;
        foreach (glob(config('view.compiled', storage_path('framework/views')).'/*.php') ?: [] as $file) {
            $removed += @unlink($file) ? 1 : 0;
        }
        foreach (['config.php', 'routes-v7.php', 'events.php'] as $cache) {
            @unlink(app()->bootstrapPath('cache/'.$cache));
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return $removed;
    }
}
