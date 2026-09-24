<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

class Time
{
    /**
     * Parse an ISO 8601 time from the USSD service into UTC, the timezone we
     * store (Eloquent writes the wall-clock time without converting it).
     */
    public static function parse(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A stored time in the election timezone, e.g. "3:42 PM".
     */
    public static function local(?Carbon $time, string $format = 'g:i A'): string
    {
        return $time ? $time->copy()->setTimezone(config('election.timezone'))->format($format) : '—';
    }
}
