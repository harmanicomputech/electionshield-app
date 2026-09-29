<?php

namespace App\Support;

use Throwable;

/**
 * After an upload, drop the compiled views and cached config/routes.
 *
 * Unzipping keeps each file's date from when the package was built, so an
 * uploaded view can look older than the server's compiled copy and Blade
 * keeps showing the old page. Every package carries a DEPLOY_ID file; when
 * it differs from the one seen last, the caches are cleared once.
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

            self::clearCaches();
            @file_put_contents($seenFile, $id);
        } catch (Throwable) {
            // Never stop a page over this.
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
