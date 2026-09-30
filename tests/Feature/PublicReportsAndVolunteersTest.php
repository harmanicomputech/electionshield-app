<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\PollingUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\Volunteer;
use App\Services\AlertFeed;
use App\Services\Broadcasting\Audience;
use App\Services\PuMonitor;
use App\Services\PushNotifier;
use App\Services\UssdSync;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

/**
 * The USSD service is open to the public: unverified public incident reports
 * and "How can you help?" volunteer sign-ups.
 */
class PublicReportsAndVolunteersTest extends TestCase
{
    use RefreshDatabase, SendsUssdEvents;

    private array $pushed = [];

    protected function setUp(): void
    {
        parent::setUp();
        Settings::flush();
        $pushed = &$this->pushed;
        $this->app->instance(PushNotifier::class, new class($pushed) extends PushNotifier
        {
            public function __construct(private array &$pushed) {}

            public function toTopic(string $topic, array $message, ?string $lga = null): int
            {
                $this->pushed[] = $topic;

                return 1;
            }
        });
        PollingUnit::query()->create(['code' => '21202633007', 'name' => 'Polling Unit 21202633007', 'ward' => 'Abakaliki Ward 01', 'lga' => 'Abakaliki', 'registered_voters' => 1507]);
    }

    private function publicIncident(array $overrides = []): array
    {
        return array_replace([
            'reference' => 'IN300001',
            'polling_unit' => ['code' => null, 'lga' => 'Ikwo', 'ward' => 'Ikwo Ward 03'],
            'type' => 'violence', 'type_label' => 'Violence', 'urgent' => true,
            'note' => 'Thugs at the school', 'agent' => null,
            'source' => 'public', 'reporter_phone' => '+2348099999999',
            'channel' => 'ussd', 'reported_at' => now()->toIso8601String(), 'rehearsal' => false,
        ], $overrides);
    }

    private function volunteer(array $overrides = []): array
    {
        return array_replace([
            'reference' => 'VL400001', 'name' => 'Chioma Uche',
            'phone_number' => '+2348099999999', 'contact_phone' => '+2348031234567',
            'lga' => 'Abakaliki', 'ward' => 'Abakaliki Ward 02',
            'roles' => ['canvass', 'youth', 'professional'], 'role_labels' => [],
            'skills' => ['legal', 'it'], 'skill_labels' => [], 'other' => null,
            'is_agent' => false, 'channel' => 'ussd',
            'registered_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(), 'rehearsal' => false,
        ], $overrides);
    }

    public function test_a_public_report_is_shown_flagged_without_alarms(): void
    {
        $this->sendEvent('incident.reported', $this->publicIncident())->assertOk();
        $this->sendEvent('incident.reported', $this->publicIncident(['reference' => 'IN300002', 'polling_unit' => ['code' => '21202633007'], 'urgent' => false]))->assertOk();

        $incident = Incident::query()->where('reference', 'IN300001')->sole();
        $this->assertSame(['public', '+2348099999999', null, 'Ikwo', 'Ikwo Ward 03'], [$incident->source, $incident->reporter_phone, $incident->polling_unit_code, $incident->lga, $incident->ward]);
        $this->assertSame('Abakaliki', Incident::query()->where('reference', 'IN300002')->value('lga'));

        // No push alerts for public reports (they're unverified), even urgent types.
        $this->assertSame([], $this->pushed);

        $coordinator = User::factory()->create();
        $this->actingAs($coordinator);
        $page = $this->get('/incidents?lga=all')->assertOk()->assertSee('Public report (unverified)')->assertSee('Member of the public')->assertSee('Ikwo Ward 03, Ikwo')->assertSee('Call +2348099999999');
        $this->get('/incidents?lga=all&source=agent')->assertOk()->assertDontSee('Thugs at the school');
        $this->get('/incidents?lga=all&source=public')->assertOk()->assertSee('Thugs at the school');
        // Not counted as urgent in the sidebar or dashboard.
        $this->get('/')->assertOk()->assertDontSee('urgent incident');

        // Pop-up: flagged, no alarm, place from the ward.
        $alert = collect(app(AlertFeed::class)->pending($coordinator))->firstWhere('reference', 'IN300001');
        $this->assertTrue($alert['public']);
        $this->assertFalse($alert['urgent']);
        $this->assertSame(['Public report (unverified)', 'Ikwo Ward 03, Ikwo', 'Member of the public', '+2348099999999'], [$alert['heading'], $alert['place'], $alert['agent'], $alert['phone']]);

        // The PU board ignores reports without a PU and never flags public ones urgent.
        $units = (new PuMonitor(false))->units();
        $this->assertSame([1, 0], [$units['21202633007']->openIncidents, $units['21202633007']->urgentIncidents]);
        $this->get('/monitor')->assertOk();
    }

    public function test_volunteers_arrive_by_webhook_and_sync_and_newer_versions_win(): void
    {
        $this->sendEvent('volunteer.registered', $this->volunteer(), 'volunteer.registered:VL400001:1')->assertOk();
        $volunteer = Volunteer::query()->sole();
        $this->assertSame(['Chioma Uche', '+2348031234567', ['canvass', 'youth', 'professional'], ['legal', 'it']], [$volunteer->name, $volunteer->contact_phone, $volunteer->roles, $volunteer->skills]);
        $this->assertSame(['Canvass in my ward', 'Mobilise youth', 'Professional skills (legal, media, medical, IT)'], $volunteer->roleLabels());

        // Updated on USSD later; an older copy arriving late doesn't undo it.
        $this->sendEvent('volunteer.registered', $this->volunteer(['roles' => ['transport'], 'skills' => [], 'updated_at' => now()->addMinute()->toIso8601String()]), 'volunteer.registered:VL400001:2')->assertOk();
        $this->sendEvent('volunteer.registered', $this->volunteer(['roles' => ['women'], 'updated_at' => now()->subDay()->toIso8601String()]), 'volunteer.registered:VL400001:0')->assertOk();
        $this->assertSame(['transport'], Volunteer::query()->sole()->roles);

        // Pulled by the sync too; an older USSD service without /volunteers is not an error.
        $missing = false;
        Http::fake(function (Request $request) use (&$missing) {
            if ($missing) {
                return Http::response(['message' => 'Not Found'], 404);
            }

            return Http::response(str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/volunteers')
                ? ['data' => [$this->volunteer(['reference' => 'VL400002', 'name' => 'Emeka Eze', 'phone_number' => '+2348077777777', 'contact_phone' => '+2348077777777'])], 'next_cursor' => null, 'server_time' => now()->toIso8601String()]
                : ['data' => [], 'next_cursor' => null, 'server_time' => now()->toIso8601String()]);
        });
        $this->assertSame(['count' => 1, 'error' => null], app(UssdSync::class)->syncResource('volunteers'));
        $this->assertSame(2, Volunteer::query()->count());

        $missing = true;
        $this->assertSame(['count' => 0, 'error' => null], app(UssdSync::class)->syncResource('volunteers'));
    }

    public function test_the_volunteers_page(): void
    {
        $this->sendEvent('volunteer.registered', $this->volunteer(), 'v1')->assertOk();
        $this->sendEvent('volunteer.registered', $this->volunteer(['reference' => 'VL400002', 'name' => 'Emeka Eze', 'phone_number' => '+2348077777777', 'contact_phone' => '+2348077777777', 'lga' => 'Ikwo', 'ward' => 'Ikwo Ward 01', 'roles' => ['transport', 'other'], 'skills' => [], 'other' => 'I have a bus', 'is_agent' => true]), 'v2')->assertOk();

        $coordinator = User::factory()->create(['name' => 'Coord One']);
        $this->actingAs($coordinator);
        $this->get('/volunteers')->assertOk()
            ->assertSee('Chioma Uche')->assertSee('Emeka Eze')->assertSee('“I have a bus”', false)->assertSee('Polling agent')
            ->assertSee('Ward 02, Abakaliki')->assertSee('Skills: Legal, IT')
            ->assertSee('https://wa.me/2348031234567', false)->assertSee('tel:+2348031234567', false)
            ->assertSeeInOrder(['Mobilise youth', '1', 'Transport and logistics', '1']);
        $this->get('/volunteers?role=transport')->assertSee('Emeka Eze')->assertDontSee('Chioma Uche');
        $this->get('/volunteers?lga=Abakaliki')->assertSee('Chioma Uche')->assertDontSee('Emeka Eze');
        $this->get('/volunteers?q=08077777777')->assertSee('Emeka Eze')->assertDontSee('Chioma Uche');

        // Follow-up.
        $chioma = Volunteer::query()->where('reference', 'VL400001')->sole();
        $this->postJson("/volunteers/{$chioma->id}/contacted", ['contacted' => 1, 'follow_up_note' => 'Will canvass on Saturday'])->assertOk();
        $this->assertSame(['Coord One', 'Will canvass on Saturday'], [$chioma->fresh()->contacted_by, $chioma->fresh()->follow_up_note]);
        $this->get('/volunteers?status=new')->assertSee('Emeka Eze')->assertDontSee('Chioma Uche');
        $this->get('/volunteers?status=contacted')->assertSee('Contacted by Coord One');

        // CSV needs "Export data" (coordinators don't have it by default); admins do.
        $this->get('/volunteers/export')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create());
        $csv = $this->get('/volunteers/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Canvass in my ward; Mobilise youth; Professional skills (legal, media, medical, IT)"', $csv);
        $this->assertStringContainsString('+2348077777777', $csv);

        // Observers can't see volunteers.
        $this->actingAs(User::factory()->role('observer')->create())->get('/volunteers')->assertForbidden();
        $this->assertContains('view_volunteers', Role::forKey('coordinator')->permissions);
    }

    public function test_volunteers_are_a_broadcast_group_for_sms(): void
    {
        $this->sendEvent('volunteer.registered', $this->volunteer(), 'v1')->assertOk();
        $this->sendEvent('volunteer.registered', $this->volunteer(['reference' => 'VL400002', 'name' => 'Emeka Eze', 'phone_number' => '+2348077777777', 'contact_phone' => '+2348077777777', 'lga' => 'Ikwo', 'ward' => 'Ikwo Ward 01']), 'v2')->assertOk();
        $audience = app(Audience::class);

        $this->assertSame(['+2348031234567', '+2348077777777'], $audience->recipients(['groups' => ['volunteers']], 'sms')->keys()->sort()->values()->all());
        $this->assertSame(['+2348077777777'], $audience->recipients(['groups' => ['volunteers'], 'lgas' => ['Ikwo']], 'sms')->keys()->all());
        $this->assertCount(0, $audience->recipients(['groups' => ['volunteers']], 'whatsapp')); // WhatsApp needs an opt-in

        // "Text them" on the Volunteers page opens a broadcast with that audience.
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/broadcasts/create?groups[]=volunteers&lgas[]=Ikwo')->assertOk()->assertSee('value="volunteers" checked', false);
    }
}
