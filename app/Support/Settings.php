<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * Settings changed in the console (the host has no terminal to edit .env).
 */
class Settings
{
    /** @var array<string, string|null>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            try {
                self::$cache = Setting::query()->pluck('value', 'key')->all();
            } catch (Throwable) {
                return $default;
            }
        }

        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Whether the dashboards show rehearsal data instead of real results.
     * Never both at once, so rehearsal figures can't leak into real totals.
     */
    public static function showingRehearsal(): bool
    {
        return self::get('data_view') === 'rehearsal';
    }
}
