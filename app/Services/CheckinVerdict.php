<?php

namespace App\Services;

use App\Models\PollingUnit;
use App\Models\Presence;
use App\Support\Geo;

/**
 * Was the agent at their polling unit when they checked in? Web check-ins
 * carry the phone's GPS position; the PU's location is set by an admin (or
 * imported). USSD check-ins have no position.
 */
class CheckinVerdict
{
    public const AT_PU = 'at_pu';

    public const AWAY = 'away';

    public const UNKNOWN_PU = 'unknown_pu';

    public const SUSPICIOUS = 'suspicious';

    public const NO_LOCATION = 'no_location';

    /**
     * @return array{status: string, label: string, class: string, distance: ?float}
     */
    public function for(Presence $presence, ?PollingUnit $unit): array
    {
        if (! $presence->hasLocation()) {
            return ['status' => self::NO_LOCATION, 'label' => $presence->channel === 'web' ? 'No location' : 'USSD: no location', 'class' => 'warn', 'distance' => null];
        }

        $accuracy = (float) ($presence->location_accuracy ?? 0);
        $suspicious = match (true) {
            ! Geo::inState($presence->latitude, $presence->longitude) => 'Outside '.config('election.state', 'Ebonyi'),
            $accuracy > 0 && $accuracy < 1 => 'Suspiciously exact GPS (fake-location app?)',
            $presence->located_at && $presence->confirmed_at && abs($presence->located_at->diffInMinutes($presence->confirmed_at)) > 30 => 'Position taken '.$presence->located_at->diffForHumans($presence->confirmed_at, true).' before sending',
            default => null,
        };

        if ($unit?->latitude === null || $unit?->longitude === null) {
            return $suspicious
                ? ['status' => self::SUSPICIOUS, 'label' => $suspicious, 'class' => 'bad', 'distance' => null]
                : ['status' => self::UNKNOWN_PU, 'label' => 'PU location not set', 'class' => '', 'distance' => null];
        }

        $distance = Geo::distance($presence->latitude, $presence->longitude, $unit->latitude, $unit->longitude);
        // Allow for the GPS reading's own error, up to 100 m. INEC's positions
        // are approximate (often one point for several PUs), so allow more.
        $approximate = $unit->hasApproximateLocation();
        $allowed = (float) config($approximate ? 'election.checkin_radius_approx_m' : 'election.checkin_radius_m') + min($accuracy, 100);
        $where = $approximate ? ' from INEC\'s approximate PU location' : ' from the PU';

        if ($suspicious) {
            return ['status' => self::SUSPICIOUS, 'label' => $suspicious.' · '.Geo::distanceLabel($distance).$where, 'class' => 'bad', 'distance' => $distance];
        }

        if ($distance > $allowed) {
            return ['status' => self::AWAY, 'label' => Geo::distanceLabel($distance).$where, 'class' => 'bad', 'distance' => $distance];
        }

        return ['status' => self::AT_PU, 'label' => ($approximate ? 'Near the PU (' : 'At the PU (').Geo::distanceLabel($distance).($approximate ? ', INEC location is approximate)' : ')'), 'class' => 'good', 'distance' => $distance];
    }
}
