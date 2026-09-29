<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\Role;
use App\Models\User;
use App\Models\UserLocation;
use App\Services\AlertFeed;
use App\Services\CheckinVerdict;
use App\Services\LocationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase, SendsUssdEvents;

    private const PHONE = '+2348012345678';

    // The PU and a spot about 1.1 km north of it.
    private const PU = [6.3249, 8.1137];

    private const AWAY = [6.3349, 8.1137];

    protected function setUp(): void
    {
        parent::setUp();
        PollingUnit::query()->create(['code' => '21202633007', 'name' => 'Polling Unit 21202633007', 'ward' => 'Abakaliki Ward 01', 'lga' => 'Abakaliki', 'registered_voters' => 1507]);
    }

    private function checkIn(array $position, int $id = 3, ?string $confirmedAt = null): TestResponse
    {
        if (! Agent::query()->exists()) {
            Agent::query()->create(['ussd_id' => 7, 'name' => 'Ada Obi', 'phone_number' => self::PHONE, 'polling_unit_code' => '21202633007']);
        }
        $this->actingAs(User::query()->where('phone', self::PHONE)->first() ?? User::factory()->agent(self::PHONE)->create(['name' => 'Ada Obi']));
        Http::fake(['ussd.test/api/field/presence' => Http::response(['message' => 'Presence confirmed.', 'presence' => ['id' => $id, 'polling_unit' => $this->unit(), 'agent' => ['name' => 'Ada Obi', 'phone_number' => self::PHONE], 'channel' => 'web', 'confirmed_at' => $confirmedAt ?? now()->toIso8601String()]], 201)]);

        return $this->post('/field/presence', $position);
    }

    public function test_check_in_needs_the_location_and_keeps_it_for_the_situation_room(): void
    {
        $this->checkIn([])->assertSessionHasErrors('latitude');
        $this->assertSame(0, Presence::query()->count());

        $this->checkIn(['latitude' => self::AWAY[0], 'longitude' => self::AWAY[1], 'location_accuracy' => 15, 'located_at' => now()->getTimestampMs()])->assertRedirect('/field');

        $presence = Presence::query()->sole();
        $this->assertEqualsWithDelta(self::AWAY[0], $presence->latitude, 0.000001);
        $this->assertSame(15.0, $presence->location_accuracy);
        $this->assertSame('check-in PU 21202633007', UserLocation::query()->sole()->action);

        // The agent is not shown where they were.
        $this->get('/field')->assertOk()->assertDontSee((string) self::AWAY[0]);
        $this->get('/locations')->assertForbidden();

        // PU location unknown until someone sets it.
        $this->assertSame(CheckinVerdict::UNKNOWN_PU, app(CheckinVerdict::class)->for($presence, $presence->pollingUnit)['status']);
        PollingUnit::query()->update(['latitude' => self::PU[0], 'longitude' => self::PU[1]]);
        $verdict = app(CheckinVerdict::class)->for($presence->fresh(), PollingUnit::query()->sole());
        $this->assertSame(CheckinVerdict::AWAY, $verdict['status']);
        $this->assertSame('1.1 km from the PU', $verdict['label']);

        // The situation room sees it, with a pop-up until reviewed.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/locations')->assertOk()->assertSee('1.1 km from the PU')->assertSee('Ada Obi')->assertSee('Mark reviewed');
        $alerts = app(AlertFeed::class)->pending($admin);
        $this->assertSame(['checkin'], array_column($alerts, 'kind'));
        $this->postJson($alerts[0]['acknowledge'])->assertOk();
        $this->assertNotNull($presence->fresh()->location_reviewed_at);
        $this->assertSame([], app(AlertFeed::class)->pending($admin));
    }

    public function test_verdicts(): void
    {
        $unit = new PollingUnit(['latitude' => self::PU[0], 'longitude' => self::PU[1]]);
        $verdict = fn (array $attributes) => app(CheckinVerdict::class)->for(new Presence([...['confirmed_at' => now(), 'channel' => 'web'], ...$attributes]), $unit)['status'];

        $this->assertSame(CheckinVerdict::AT_PU, $verdict(['latitude' => 6.3260, 'longitude' => 8.1137, 'location_accuracy' => 20, 'located_at' => now()]));
        // 390 m away with a 100 m GPS error: still within 300 m + 100 m.
        $this->assertSame(CheckinVerdict::AT_PU, $verdict(['latitude' => 6.3284, 'longitude' => 8.1137, 'location_accuracy' => 150, 'located_at' => now()]));
        $this->assertSame(CheckinVerdict::AWAY, $verdict(['latitude' => self::AWAY[0], 'longitude' => self::AWAY[1], 'location_accuracy' => 10, 'located_at' => now()]));
        $this->assertSame(CheckinVerdict::SUSPICIOUS, $verdict(['latitude' => 6.3250, 'longitude' => 8.1137, 'location_accuracy' => 0.5, 'located_at' => now()]));
        $this->assertSame(CheckinVerdict::SUSPICIOUS, $verdict(['latitude' => 6.3250, 'longitude' => 8.1137, 'location_accuracy' => 10, 'located_at' => now()->subHour()]));
        $this->assertSame(CheckinVerdict::SUSPICIOUS, $verdict(['latitude' => 6.5244, 'longitude' => 3.3792, 'location_accuracy' => 10, 'located_at' => now()])); // Lagos
        $this->assertSame(CheckinVerdict::NO_LOCATION, $verdict(['channel' => 'ussd']));
        $this->assertSame('USSD: no location', app(CheckinVerdict::class)->for(new Presence(['channel' => 'ussd']), $unit)['label']);
    }

    public function test_a_check_in_can_set_the_pus_location(): void
    {
        $this->checkIn(['latitude' => 6.3251, 'longitude' => 8.1139, 'location_accuracy' => 8]);
        $presence = Presence::query()->sole();

        $this->actingAs(User::factory()->admin()->create(['name' => 'Admin One']));
        $this->post("/locations/checkins/{$presence->id}/pu")->assertRedirect();

        $unit = PollingUnit::query()->sole();
        $this->assertEqualsWithDelta(6.3251, $unit->latitude, 0.000001);
        $this->assertSame('check-in by Ada Obi, set by Admin One', $unit->location_source);
        $this->get('/locations')->assertSee('At the PU');
    }

    public function test_checking_in_again_by_ussd_drops_the_old_position(): void
    {
        $this->checkIn(['latitude' => 6.3251, 'longitude' => 8.1139], 3, now()->subHour()->toIso8601String());
        $this->assertTrue(Presence::query()->sole()->hasLocation());

        $this->sendEvent('presence.confirmed', ['id' => 3, 'polling_unit' => $this->unit(), 'agent' => ['name' => 'Ada Obi', 'phone_number' => self::PHONE], 'channel' => 'ussd', 'confirmed_at' => now()->toIso8601String()])->assertOk();
        $this->assertFalse(Presence::query()->sole()->hasLocation());
    }

    public function test_coordinators_are_tracked_but_not_blocked_and_admins_are_not_tracked(): void
    {
        $coordinator = User::factory()->create(['name' => 'Coord One']);
        $this->assertTrue($coordinator->sharesLocation());
        $this->actingAs($coordinator)->get('/')->assertSee('data-location=', false);

        $this->postJson('/location', ['status' => 'ok', 'latitude' => 6.32, 'longitude' => 8.11, 'accuracy' => 30, 'located_at' => now()->getTimestampMs(), 'action' => 'opened the app'])->assertOk();
        $this->postJson('/location', ['status' => 'denied', 'action' => 'using the app'])->assertOk();

        // Actions carry the position; refusing does not stop them.
        $this->withHeader('X-ES-Location', 'denied')->put('/account', ['name' => 'Coord One'])->assertRedirect();
        $this->flushHeaders()->put('/account', ['name' => 'Coord One', '_es_location' => '6.33,8.12,25,'.now()->getTimestampMs()])->assertRedirect();
        $this->get('/account')->assertOk(); // reading a page is not recorded

        $this->assertSame([UserLocation::OK, UserLocation::DENIED, UserLocation::DENIED, UserLocation::OK], UserLocation::query()->where('user_id', $coordinator->id)->orderBy('id')->pluck('status')->all());
        $this->assertSame('account update', UserLocation::query()->latest('id')->first()->action);

        $admin = User::factory()->admin()->create();
        $this->assertFalse($admin->sharesLocation());
        $this->actingAs($admin)->get('/')->assertDontSee('data-location=', false);
        $this->postJson('/location', ['status' => 'ok', 'latitude' => 6.32, 'longitude' => 8.11, 'action' => 'opened the app'])->assertNoContent();
        $this->assertSame(0, UserLocation::query()->where('user_id', $admin->id)->count());

        $refuser = User::factory()->create(['name' => 'Coord Two']);
        UserLocation::query()->create(['user_id' => $refuser->id, 'status' => UserLocation::DENIED, 'action' => 'opened the app']);
        $this->get('/locations?tab=people')->assertRedirect('/locations/people');
        $this->get('/locations/people')->assertOk()->assertSeeInOrder(['Coord One', 'Located', 'Coord Two', 'Location refused']);
        $this->get("/locations/people/{$coordinator->id}")->assertOk()->assertSee('Opened the app')->assertSee('Location refused')->assertSee('Download CSV');

        // Observers don't see locations.
        $this->actingAs(User::factory()->role('observer')->create())->get('/locations')->assertForbidden();
    }

    public function test_the_header_is_parsed_strictly(): void
    {
        $this->assertNull(LocationRecorder::parse('hello'));
        $this->assertNull(LocationRecorder::parse('91,8,1,1'));
        $this->assertSame(['status' => 'denied'], LocationRecorder::parse('denied'));
        $this->assertSame(6.5, LocationRecorder::parse('6.5,8.1,12,1700000000000')['latitude']);
    }

    public function test_the_people_map_filters_by_time_frame_and_shows_a_persons_history(): void
    {
        $this->travelTo(now()->startOfMinute());
        $agent = User::factory()->agent(self::PHONE)->create(['name' => 'Ada Obi', 'lga' => 'Abakaliki']);
        $coordinator = User::factory()->create(['name' => 'Coord Far', 'lga' => 'Ohaukwu']);
        $silent = User::factory()->create(['name' => 'Coord Silent']);
        $at = fn (User $user, string $when, array $position, string $action = 'using the app') => UserLocation::query()->forceCreate([
            'user_id' => $user->id, 'status' => UserLocation::OK, 'latitude' => $position[0], 'longitude' => $position[1], 'accuracy' => 15,
            'action' => $action, 'located_at' => now()->modify($when), 'created_at' => now()->modify($when), 'updated_at' => now(),
        ]);
        $at($agent, '-3 days', [6.30, 8.10], 'opened the app');
        $at($agent, '-20 minutes', [6.3249, 8.1137]);
        $at($agent, '-5 minutes', [6.3349, 8.1137], 'field materials');
        $at($coordinator, '-2 days', [6.5244, 3.3792]); // Lagos

        $this->actingAs(User::factory()->admin()->create());

        // Last hour: only the agent; the coordinator was last seen days ago.
        $hour = $this->get('/locations/people?range=hour')->assertOk()->assertSee('Last hour');
        $markers = $this->mapData($hour->getContent(), 'people-map-data')['markers'];
        $this->assertSame(['Ada Obi'], array_column($markers, 'name'));
        $this->assertSame(6.3349, $markers[0]['lat']);
        $hour->assertSeeInOrder(['Ada Obi', 'Located', 'Coord Far', 'Not seen', 'Coord Silent', 'Not seen']);

        // Last 7 days: the coordinator shows, flagged outside the state.
        $week = $this->get('/locations/people?range=week');
        $this->assertEqualsCanonicalizing(['Ada Obi', 'Coord Far'], array_column($this->mapData($week->getContent(), 'people-map-data')['markers'], 'name'));
        $week->assertSee('Outside the state');
        $this->get('/locations/people?range=week&status=outside')->assertSee('Coord Far')->assertDontSee('Ada Obi</b>', false);
        $this->get('/locations/people?range=week&group=agent')->assertSee('Ada Obi')->assertDontSee('Coord Far');
        $this->get('/locations/people?range=week&q=silent')->assertSee('Coord Silent')->assertDontSee('Ada Obi');

        // A person's history: the path in the time frame, newest first, with distances.
        $history = $this->get("/locations/people/{$agent->id}?range=week")->assertOk()
            ->assertSeeInOrder(['Field materials', 'moved 1.1 km', 'Using the app', 'Opened the app']);
        $this->assertCount(3, $this->mapData($history->getContent(), 'person-map-data')['path']);
        $this->assertCount(2, $this->mapData($this->get("/locations/people/{$agent->id}?range=hour")->getContent(), 'person-map-data')['path']);

        $csv = $this->get("/locations/people/{$agent->id}/history.csv?range=week")->assertOk()->streamedContent();
        $this->assertStringContainsString('"field materials",ok,6.3349,8.1137,15', $csv);
        $this->assertSame(4, substr_count(trim($csv), "\n") + 1);

        // Coordinators without "See where people are" can't open it.
        $this->actingAs($coordinator)->get('/locations/people')->assertForbidden();
    }

    public function test_any_user_can_be_tracked_or_not_per_person(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Main Admin']);
        $otherAdmin = User::factory()->admin()->create(['name' => 'Deputy Admin', 'email' => 'deputy@example.com']);
        $observer = User::factory()->role('observer')->create(['name' => 'Obi Observer']);
        $coordinator = User::factory()->create(['name' => 'Coord Quiet']);
        $this->assertFalse($otherAdmin->sharesLocation());
        $this->assertFalse($observer->sharesLocation());

        $this->actingAs($admin);
        // From the People map: "Add someone to the map".
        $this->get('/locations/people')->assertOk()->assertSee('Add someone to the map')->assertSee('Deputy Admin · Admin')->assertSee('Obi Observer · Observer');
        $this->post("/locations/people/{$otherAdmin->id}/tracking", ['track_location' => 'always'])->assertRedirect();
        $this->assertTrue($otherAdmin->fresh()->sharesLocation());
        // From the Users page.
        $this->put("/users/{$observer->id}", ['role' => 'observer', 'track_location' => 'always'])->assertRedirect();
        $this->put("/users/{$coordinator->id}", ['role' => 'coordinator', 'track_location' => 'never'])->assertRedirect();
        $this->assertTrue($observer->fresh()->sharesLocation());
        $this->assertFalse($coordinator->fresh()->sharesLocation());
        $this->assertSame('Location never recorded', $coordinator->fresh()->trackingLabel());

        // The tracked admin's app now asks for location and records it.
        $this->actingAs($otherAdmin->fresh())->get('/')->assertSee('data-location=', false);
        $this->postJson('/location', ['status' => 'ok', 'latitude' => 6.32, 'longitude' => 8.11, 'action' => 'opened the app'])->assertOk();
        $this->actingAs($coordinator->fresh())->get('/')->assertDontSee('data-location=', false);

        // They are on the map; "Who" lists every role there.
        $this->actingAs($admin);
        $page = $this->get('/locations/people?range=hour')->assertOk()->assertSee('<option value="admin"', false)->assertSee('<option value="observer"', false);
        $this->assertSame(['Deputy Admin'], array_column($this->mapData($page->getContent(), 'people-map-data')['markers'], 'name'));
        $this->get('/locations/people?group=observer')->assertSee('Obi Observer')->assertDontSee('Deputy Admin</b>', false);
        $this->get("/locations/people/{$otherAdmin->id}")->assertOk()->assertSee('Location always recorded');

        // Back to the role's rule.
        $this->post("/locations/people/{$otherAdmin->id}/tracking", ['track_location' => 'role'])->assertRedirect();
        $this->assertFalse($otherAdmin->fresh()->sharesLocation());

        // Only people who manage users can change it.
        $viewer = User::factory()->role('observer')->create();
        Role::query()->where('key', 'observer')->first()?->forceFill(['permissions' => [...(Role::query()->where('key', 'observer')->first()->permissions ?? []), 'view_locations']])->save();
        $this->actingAs($viewer->fresh())->post("/locations/people/{$observer->id}/tracking", ['track_location' => 'never'])->assertForbidden();
    }

    public function test_the_people_map_still_opens_if_the_map_script_was_not_uploaded(): void
    {
        $script = public_path('js/locations-map.js');
        rename($script, $script.'.bak');

        try {
            $this->actingAs(User::factory()->admin()->create())->get('/locations/people')->assertOk()->assertSee('locations-map.js?v=0', false);
        } finally {
            rename($script.'.bak', $script);
        }
    }

    private function mapData(string $html, string $id): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="'.$id.'">(.*?)</script>#s', $html, $match));

        return json_decode($match[1], true);
    }
}
