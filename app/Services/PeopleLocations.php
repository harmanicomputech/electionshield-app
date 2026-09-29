<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\User;
use App\Models\UserLocation;
use App\Support\Geo;
use App\Support\Settings;
use App\Support\Time;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where tracked people (roles with "Location is recorded") were: everyone's
 * last position in a time frame, and one person's full history.
 */
class PeopleLocations
{
    /** @var array<string, array{0: string, 1: int}> key => [label, hours] */
    public const RANGES = [
        'hour' => ['Last hour', 1],
        'day' => ['Last 24 hours', 24],
        'week' => ['Last 7 days', 24 * 7],
        'month' => ['Last 30 days', 24 * 30],
    ];

    /** Readings less precise than this are not used for distance travelled. */
    private const PATH_ACCURACY_M = 200;

    public static function since(string $range): Carbon
    {
        return now()->subHours(self::RANGES[$range][1] ?? 24);
    }

    /**
     * @return Collection<int, User>
     */
    public function tracked(): Collection
    {
        return User::query()->orderBy('name')->get()
            ->filter(fn (User $user) => $user->sharesLocation())->values();
    }

    /**
     * One row per tracked person: their last reading and last position in the
     * range, their last reading ever, and how many readings in the range.
     *
     * @param  Collection<int, User>  $people
     * @return Collection<int, array<string, mixed>>
     */
    public function overview(Collection $people, Carbon $since): Collection
    {
        $ids = $people->pluck('id');
        $latestIds = fn ($query) => UserLocation::query()->selectRaw('max(id)')->whereIn('user_id', $ids)->groupBy('user_id')->when($query, $query);

        $lastFix = UserLocation::query()->whereIn('id', $latestIds(fn ($q) => $q->where('created_at', '>=', $since)->where('status', UserLocation::OK)))->get()->keyBy('user_id');
        $lastInRange = UserLocation::query()->whereIn('id', $latestIds(fn ($q) => $q->where('created_at', '>=', $since)))->get()->keyBy('user_id');
        $lastEver = UserLocation::query()->whereIn('id', $latestIds(null))->get()->keyBy('user_id');
        $counts = UserLocation::query()->whereIn('user_id', $ids)->where('created_at', '>=', $since)->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id');

        return $people->map(function (User $user) use ($lastFix, $lastInRange, $lastEver, $counts) {
            $fix = $lastFix[$user->id] ?? null;
            $latest = $lastInRange[$user->id] ?? null;
            $status = match (true) {
                $latest === null => 'not_seen',
                $latest->status === UserLocation::DENIED => 'denied',
                $fix === null => 'unavailable',
                ! Geo::inState($fix->latitude, $fix->longitude) => 'outside',
                default => 'located',
            };

            return [
                'user' => $user,
                'fix' => $fix,
                'latest' => $latest,
                'ever' => $lastEver[$user->id] ?? null,
                'points' => (int) ($counts[$user->id] ?? 0),
                'status' => $status,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function marker(array $row, bool $phones): array
    {
        /** @var User $user */
        $user = $row['user'];
        $fix = $row['fix'];

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->roleName(),
            'group' => $user->isAgent() ? 'agent' : ($user->role === 'coordinator' ? 'coordinator' : 'other'),
            'lga' => $user->lga,
            'phone' => $phones ? $user->phone : null,
            'lat' => round($fix->latitude, 6),
            'lng' => round($fix->longitude, 6),
            'acc' => $fix->accuracy !== null ? (int) round($fix->accuracy) : null,
            'when' => Time::local($fix->created_at, 'j M, g:i A'),
            'ago' => $fix->created_at->diffForHumans(),
            'action' => $fix->action,
            'outside' => $row['status'] === 'outside',
            'url' => route('locations.person', ['user' => $user, 'range' => request('range')]),
        ];
    }

    /**
     * One person's readings in the range (oldest first), the path between
     * their positions, and for agents their PU and check-ins.
     *
     * @return array<string, mixed>
     */
    public function history(User $user, Carbon $since): array
    {
        $entries = UserLocation::query()->where('user_id', $user->id)->where('created_at', '>=', $since)->orderBy('created_at')->orderBy('id')->limit(3000)->get();

        $previous = null;
        $distance = 0.0;
        $steps = $entries->map(function (UserLocation $entry) use (&$previous, &$distance) {
            $moved = null;

            if ($entry->status === UserLocation::OK) {
                if ($previous) {
                    $moved = Geo::distance($previous->latitude, $previous->longitude, $entry->latitude, $entry->longitude);
                    if (($entry->accuracy ?? 0) <= self::PATH_ACCURACY_M && ($previous->accuracy ?? 0) <= self::PATH_ACCURACY_M) {
                        $distance += $moved;
                    }
                }
                $previous = $entry;
            }

            return ['entry' => $entry, 'moved' => $moved, 'outside' => $entry->status === UserLocation::OK && ! Geo::inState($entry->latitude, $entry->longitude)];
        });

        $fixes = $entries->where('status', UserLocation::OK)->values();
        $agent = $user->isAgent() && filled($user->phone) ? Agent::query()->where('phone_number', $user->phone)->first() : null;
        $unit = $agent?->polling_unit_code ? PollingUnit::query()->where('code', $agent->polling_unit_code)->first() : null;
        $checkins = $agent ? Presence::query()->with('pollingUnit')->where('agent_phone', $user->phone)->where('rehearsal', Settings::showingRehearsal())->where('confirmed_at', '>=', $since)->latest('confirmed_at')->get() : collect();

        return [
            'steps' => $steps,
            'fixes' => $fixes,
            'distance' => $distance,
            'refused' => $entries->where('status', UserLocation::DENIED)->count(),
            'unavailable' => $entries->where('status', UserLocation::UNAVAILABLE)->count(),
            'outside' => $steps->where('outside', true)->count(),
            'first' => $entries->first(),
            'last' => $entries->last(),
            'lastEver' => UserLocation::query()->where('user_id', $user->id)->latest('id')->first(),
            'agent' => $agent,
            'unit' => $unit,
            'checkins' => $checkins,
            'truncated' => $entries->count() >= 3000,
        ];
    }
}
