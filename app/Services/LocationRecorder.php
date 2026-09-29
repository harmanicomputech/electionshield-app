<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserLocation;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Stores where a person was (roles with "Location is recorded"). The phone
 * sends its last GPS fix with each action (header X-ES-Location) and a ping
 * when the app opens and every few minutes; or "denied" when the person
 * refused location.
 */
class LocationRecorder
{
    /**
     * Parse the X-ES-Location header: "lat,lng,accuracy,epochMs" or "denied" / "unavailable".
     *
     * @return array{status: string, latitude?: float, longitude?: float, accuracy?: float, located_at?: Carbon}|null
     */
    public static function parse(?string $header): ?array
    {
        $header = trim((string) $header);

        if (in_array($header, [UserLocation::DENIED, UserLocation::UNAVAILABLE], true)) {
            return ['status' => $header];
        }

        $parts = explode(',', $header);

        if (count($parts) !== 4 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return null;
        }

        [$lat, $lng, $accuracy, $time] = array_map('floatval', $parts);

        if (abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return [
            'status' => UserLocation::OK,
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy' => max(0, $accuracy),
            'located_at' => $time > 0 ? Carbon::createFromTimestampMs((int) $time) : now(),
        ];
    }

    /**
     * @param  array{status: string, latitude?: float, longitude?: float, accuracy?: float, located_at?: Carbon}  $location
     */
    public function record(User $user, array $location, string $action): ?UserLocation
    {
        if (! $user->sharesLocation()) {
            return null;
        }

        try {
            return UserLocation::create([
                'user_id' => $user->id,
                'status' => $location['status'],
                'latitude' => $location['latitude'] ?? null,
                'longitude' => $location['longitude'] ?? null,
                'accuracy' => $location['accuracy'] ?? null,
                'action' => mb_substr($action, 0, 150),
                'located_at' => $location['located_at'] ?? null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
