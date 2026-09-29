<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\User;
use App\Models\UserLocation;
use App\Services\CheckinVerdict;
use App\Services\LocationRecorder;
use App\Services\PeopleLocations;
use App\Support\Audit;
use App\Support\Geo;
use App\Support\Permission;
use App\Support\Settings;
use App\Support\Time;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Locations: where agents checked in, measured against their PU, and where
 * people whose role has "Location is recorded" were when they used the app.
 */
class LocationController extends Controller
{
    /**
     * The phone's position when the app opens, every few minutes while it is
     * open, or "denied" when the person refused location.
     */
    public function ping(Request $request, LocationRecorder $recorder): Response|JsonResponse
    {
        if (! $request->user()->sharesLocation()) {
            return response()->noContent();
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in([UserLocation::OK, UserLocation::DENIED, UserLocation::UNAVAILABLE])],
            'latitude' => ['required_if:status,ok', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['required_if:status,ok', 'nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'located_at' => ['nullable', 'integer', 'min:0'],
            'action' => ['required', Rule::in(['opened the app', 'using the app'])],
        ]);

        $header = $validated['status'] === UserLocation::OK
            ? implode(',', [$validated['latitude'], $validated['longitude'], $validated['accuracy'] ?? 0, $validated['located_at'] ?? 0])
            : $validated['status'];

        $recorder->record($request->user(), LocationRecorder::parse($header), $validated['action']);

        return response()->json(['status' => 'recorded']);
    }

    public function index(Request $request, CheckinVerdict $verdicts): View|RedirectResponse
    {
        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['checkins', 'people'])],
            'verdict' => ['nullable', Rule::in(['all', CheckinVerdict::AWAY, CheckinVerdict::SUSPICIOUS, CheckinVerdict::UNKNOWN_PU, CheckinVerdict::AT_PU, CheckinVerdict::NO_LOCATION])],
            'user' => ['nullable', 'integer'],
        ]);
        $tab = $validated['tab'] ?? 'checkins';

        if ($tab === 'people') {
            return redirect()->route($validated['user'] ?? null ? 'locations.person' : 'locations.people', array_filter(['user' => $validated['user'] ?? null]));
        }

        $presences = Presence::query()->with('pollingUnit')
            ->where('rehearsal', Settings::showingRehearsal())
            ->when($request->user()->lga, fn ($query, $lga) => $query->where('lga', $lga))
            ->latest('confirmed_at')->limit(500)->get();

        $rows = $presences->map(fn (Presence $presence) => ['presence' => $presence, 'verdict' => $verdicts->for($presence, $presence->pollingUnit)]);
        $counts = $rows->countBy(fn ($row) => $row['verdict']['status']);
        $verdict = $validated['verdict'] ?? 'all';

        return view('locations.checkins', [
            'rows' => $verdict === 'all' ? $rows : $rows->filter(fn ($row) => $row['verdict']['status'] === $verdict)->values(),
            'counts' => $counts,
            'total' => $rows->count(),
            'verdict' => $verdict,
            'radius' => (int) config('election.checkin_radius_m'),
        ]);
    }

    /**
     * Everyone tracked: a map of where each was last seen in the time frame,
     * and the list (click a person for their history).
     */
    public function people(Request $request, PeopleLocations $locations): View
    {
        $filters = $request->validate([
            'range' => ['nullable', Rule::in(array_keys(PeopleLocations::RANGES))],
            'group' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::in(['all', 'located', 'outside', 'denied', 'unavailable', 'not_seen'])],
            'lga' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $range = $filters['range'] ?? 'day';
        $group = $filters['group'] ?? 'all';
        $status = $filters['status'] ?? 'all';
        $lga = $filters['lga'] ?? null;
        $search = trim((string) ($filters['q'] ?? ''));

        $people = $locations->tracked();
        $trackedIds = $people->pluck('id');
        $lgas = $people->pluck('lga')->filter()->unique()->sort()->values();
        $roles = $people->mapWithKeys(fn (User $user) => [$user->role => $user->roleName()])->sort();
        $people = $people
            ->when($group !== 'all', fn ($all) => $all->where('role', $group))
            ->when($lga, fn ($all) => $all->where('lga', $lga))
            ->when($search !== '', fn ($all) => $all->filter(fn (User $user) => str_contains(mb_strtolower($user->name.' '.$user->phone.' '.$user->email), mb_strtolower($search))))
            ->values();

        $rows = $locations->overview($people, PeopleLocations::since($range));
        $counts = $rows->countBy('status');
        $order = ['located' => 0, 'outside' => 0, 'unavailable' => 1, 'denied' => 2, 'not_seen' => 3];
        $listed = $rows
            ->when($status !== 'all', fn ($all) => $all->where('status', $status))
            ->sortBy([
                fn ($a, $b) => $order[$a['status']] <=> $order[$b['status']],
                fn ($a, $b) => ($b['latest'] ?? $b['ever'])?->id <=> ($a['latest'] ?? $a['ever'])?->id,
            ])->values();
        $page = (int) ($filters['page'] ?? 1);
        $perPage = 60;
        $phones = $request->user()->can(Permission::VIEW_AGENTS);

        return view('locations.people', [
            'range' => $range,
            'group' => $group,
            'status' => $status,
            'lga' => $lga,
            'search' => $search,
            'lgas' => $lgas,
            'roles' => $roles,
            'canManage' => $request->user()->can(Permission::MANAGE_USERS),
            'untracked' => $request->user()->can(Permission::MANAGE_USERS) ? User::query()->whereNotIn('id', $trackedIds)->orderBy('name')->get() : collect(),
            'counts' => $counts,
            'total' => $rows->count(),
            'rows' => $listed->forPage($page, $perPage)->values(),
            'page' => $page,
            'pages' => max(1, (int) ceil($listed->count() / $perPage)),
            'listedCount' => $listed->count(),
            'markers' => $rows->filter(fn ($row) => $row['fix'] !== null && ($status === 'all' || $row['status'] === $status))->map(fn ($row) => $locations->marker($row, $phones))->values(),
            'phones' => $phones,
            'bounds' => config('election.state_bounds'),
        ]);
    }

    /**
     * One person's location history in the time frame: path on the map and
     * a timeline, plus (for agents) their PU and check-ins.
     */
    public function person(Request $request, User $user, PeopleLocations $locations, CheckinVerdict $verdicts): View
    {
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(PeopleLocations::RANGES))]])['range'] ?? 'day';
        $history = $locations->history($user, PeopleLocations::since($range));

        $group = $user->isAgent() ? 'agent' : ($user->role === 'coordinator' ? 'coordinator' : 'other');
        $unit = $history['unit'];

        return view('locations.person', [
            'person' => $user,
            'group' => $group,
            'range' => $range,
            'history' => $history,
            'phones' => $request->user()->can(Permission::VIEW_AGENTS),
            'canManage' => $request->user()->can(Permission::MANAGE_USERS),
            'verdicts' => $history['checkins']->mapWithKeys(fn (Presence $presence) => [$presence->id => $verdicts->for($presence, $presence->pollingUnit)]),
            'mapData' => [
                'path' => $this->path($history['fixes']),
                'group' => $group,
                'unit' => $unit?->latitude !== null ? ['lat' => $unit->latitude, 'lng' => $unit->longitude, 'name' => $unit->name, 'code' => $unit->code] : null,
                'bounds' => config('election.state_bounds'),
            ],
        ]);
    }

    /**
     * @param  Collection<int, UserLocation>  $fixes
     */
    private function path(Collection $fixes): Collection
    {
        return $fixes->map(fn (UserLocation $fix) => [
            'lat' => round($fix->latitude, 6),
            'lng' => round($fix->longitude, 6),
            'acc' => $fix->accuracy !== null ? (int) round($fix->accuracy) : null,
            'when' => Time::local($fix->created_at, 'j M, g:i:s A'),
            'action' => $fix->action,
            'id' => $fix->id,
        ])->values();
    }

    /**
     * Turn location recording on or off for one person (any role, admins included).
     */
    public function tracking(Request $request, User $user): RedirectResponse
    {
        $value = $request->validate(['track_location' => ['required', Rule::in(['role', User::TRACK_ALWAYS, User::TRACK_NEVER])]])['track_location'];
        $user->forceFill(['track_location' => $value === 'role' ? null : $value])->save();
        Audit::record('user.tracking', "{$user->name}: ".mb_strtolower($user->trackingLabel()));

        return back()->with('status', "{$user->name}: {$user->trackingLabel()}.".($user->sharesLocation() ? ' Their position is recorded from the next time they open the app (their phone asks them to allow location).' : ''));
    }

    public function personCsv(Request $request, User $user): StreamedResponse
    {
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(PeopleLocations::RANGES))]])['range'] ?? 'day';
        $entries = UserLocation::query()->where('user_id', $user->id)->where('created_at', '>=', PeopleLocations::since($range))->orderBy('created_at')->orderBy('id')->cursor();
        Audit::record('locations.export', "Downloaded {$user->name}'s location history ({$range})");
        $name = 'location-history-'.Str::slug($user->name).'-'.now()->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($entries) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['time (Africa/Lagos)', 'what', 'status', 'latitude', 'longitude', 'accuracy_m', 'position_time', 'map']);
            foreach ($entries as $entry) {
                $ok = $entry->status === UserLocation::OK;
                fputcsv($out, [
                    Time::local($entry->created_at, 'Y-m-d H:i:s'),
                    $entry->action,
                    $entry->status,
                    $ok ? $entry->latitude : '',
                    $ok ? $entry->longitude : '',
                    $ok && $entry->accuracy !== null ? round($entry->accuracy) : '',
                    $entry->located_at ? Time::local($entry->located_at, 'Y-m-d H:i:s') : '',
                    $ok ? Geo::mapLink($entry->latitude, $entry->longitude) : '',
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * Use a check-in's position as the PU's location (after checking it on the map).
     */
    public function setPollingUnitLocation(Request $request, Presence $presence): RedirectResponse
    {
        abort_unless($presence->hasLocation(), 422, 'This check-in has no location.');
        $unit = PollingUnit::query()->where('code', $presence->polling_unit_code)->firstOrFail();

        $unit->forceFill([
            'latitude' => $presence->latitude,
            'longitude' => $presence->longitude,
            'location_source' => mb_substr("check-in by {$presence->agent_name}, set by {$request->user()->name}", 0, 100),
        ])->save();
        Audit::record('pu.location', "Set the location of PU {$unit->code} from {$presence->agent_name}'s check-in ({$presence->latitude}, {$presence->longitude})");

        return back()->with('status', "Saved as the location of {$unit->name}. Check-ins there are now measured against it.");
    }

    public function review(Request $request, Presence $presence): RedirectResponse|JsonResponse
    {
        if ($presence->location_reviewed_at === null) {
            $presence->forceFill(['location_reviewed_at' => now(), 'location_reviewed_by' => $request->user()->name])->save();
            Audit::record('checkin.reviewed', "Reviewed {$presence->agent_name}'s check-in location at PU {$presence->polling_unit_code}");
        }

        return $request->expectsJson() ? response()->json(['status' => 'reviewed']) : back()->with('status', 'Marked as reviewed.');
    }
}
