<?php

namespace App\Support;

/**
 * Distances and map links for recorded locations.
 */
final class Geo
{
    /**
     * Metres between two points (haversine).
     */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rad = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lng2 - $lng1) * $rad / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function mapLink(float $lat, float $lng): string
    {
        return 'https://www.google.com/maps?q='.round($lat, 6).','.round($lng, 6);
    }

    public static function distanceLabel(float $metres): string
    {
        return $metres >= 1000 ? number_format($metres / 1000, 1).' km' : (int) round($metres).' m';
    }

    /**
     * Roughly inside the state (a wide box around Ebonyi), to catch
     * positions that are plainly elsewhere or faked.
     */
    public static function inState(float $lat, float $lng): bool
    {
        [$minLat, $maxLat, $minLng, $maxLng] = config('election.state_bounds');

        return $lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng;
    }
}
