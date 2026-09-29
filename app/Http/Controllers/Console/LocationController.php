<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\User;
use App\Models\UserLocation;
use App\Services\CheckinVerdict;
use App\Services\LocationRecorder;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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

    public function index(Request $request, CheckinVerdict $verdicts): View
    {
        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['checkins', 'people'])],
            'verdict' => ['nullable', Rule::in(['all', CheckinVerdict::AWAY, CheckinVerdict::SUSPICIOUS, CheckinVerdict::UNKNOWN_PU, CheckinVerdict::AT_PU, CheckinVerdict::NO_LOCATION])],
            'user' => ['nullable', 'integer'],
        ]);
        $tab = $validated['tab'] ?? 'checkins';

        if ($tab === 'people') {
            return $this->people($validated['user'] ?? null);
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

    private function people(?int $userId): View
    {
        $tracked = User::query()->where('role', '!=', 'admin')->orderBy('name')->get()->filter(fn (User $user) => $user->sharesLocation())->values();
        $latest = UserLocation::query()->whereIn('id', UserLocation::query()->selectRaw('max(id)')->whereIn('user_id', $tracked->pluck('id'))->groupBy('user_id'))->get()->keyBy('user_id');
        $lastFix = UserLocation::query()->whereIn('id', UserLocation::query()->selectRaw('max(id)')->whereIn('user_id', $tracked->pluck('id'))->where('status', UserLocation::OK)->groupBy('user_id'))->get()->keyBy('user_id');
        $person = $userId ? $tracked->firstWhere('id', $userId) : null;

        return view('locations.people', [
            'people' => $tracked,
            'latest' => $latest,
            'lastFix' => $lastFix,
            'person' => $person,
            'history' => $person ? UserLocation::query()->where('user_id', $person->id)->latest()->limit(200)->get() : collect(),
        ]);
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
