<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Result;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class AlertPopupTest extends TestCase
{
    use RefreshDatabase, SendsUssdEvents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sendEvent('result.submitted', $this->resultPayload(['reference' => 'RS1', 'channel' => 'web', 'submitted_at' => now()->subMinutes(2)->toIso8601String()]))->assertOk();
        $this->sendEvent('incident.reported', [
            'reference' => 'IN1', 'polling_unit' => $this->unit('21802700001', 'Ikwo', 'Ikwo Ward 01'), 'type' => 'violence', 'type_label' => 'Violence', 'urgent' => true,
            'note' => 'Thugs at the PU', 'agent' => ['name' => 'Chika Eze', 'phone_number' => '+2348032223333'], 'reported_at' => now()->subMinute()->toIso8601String(), 'rehearsal' => false,
        ])->assertOk();
        // Old reports (a full import) never pop up.
        $this->sendEvent('result.submitted', $this->resultPayload(['reference' => 'RS0', 'polling_unit' => $this->unit('21202633001'), 'submitted_at' => now()->subDays(2)->toIso8601String()]))->assertOk();
    }

    public function test_coordinators_get_new_results_and_incidents_with_channel_and_agent(): void
    {
        $this->actingAs(User::factory()->create());

        $items = $this->getJson('/alerts')->assertOk()->json('items');

        $this->assertSame(['IN1', 'RS1'], array_column($items, 'reference'));
        $this->assertSame('Urgent incident', $items[0]['heading']);
        $this->assertSame('USSD', $items[0]['channel']);
        $this->assertSame('+2348032223333', $items[0]['phone']);
        $this->assertSame('Web app', $items[1]['channel']);
        $this->assertSame('Ada Obi', $items[1]['agent']);
        $this->assertSame(610, $items[1]['votes']['APC']);
        $this->assertSame('Polling Unit 21202633007', $items[1]['place']);
    }

    public function test_acknowledging_and_resolving_clear_the_pop_up_for_everyone(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Coord One']));

        $this->postJson('/results/RS1/acknowledge')->assertOk();
        $this->postJson('/incidents/IN1/resolve', ['resolution_note' => 'Police arrived'])->assertOk();

        $this->assertSame('Coord One', Result::query()->where('reference', 'RS1')->value('acknowledged_by'));
        $this->assertSame('Police arrived', Incident::query()->sole()->resolution_note);
        $this->actingAs(User::factory()->create());
        $this->assertSame([], $this->getJson('/alerts')->json('items'));
    }

    public function test_remind_me_later_is_per_person_and_comes_back(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        $this->postJson('/alerts/snooze', ['reference' => 'IN1', 'minutes' => 15])->assertOk();
        $this->postJson('/alerts/snooze', ['reference' => 'IN1', 'minutes' => 7])->assertJsonValidationErrors('minutes');
        $this->assertSame(['RS1'], array_column($this->getJson('/alerts')->json('items'), 'reference'));

        // Someone else still sees it.
        $this->actingAs(User::factory()->create());
        $this->assertContains('IN1', array_column($this->getJson('/alerts')->json('items'), 'reference'));

        $this->actingAs($me);
        $this->travel(16)->minutes();
        $this->assertContains('IN1', array_column($this->getJson('/alerts')->json('items'), 'reference'));
    }

    public function test_home_lga_and_permissions_decide_who_gets_what(): void
    {
        $this->actingAs(User::factory()->create(['lga' => 'Ikwo']));
        $this->assertSame(['IN1'], array_column($this->getJson('/alerts')->json('items'), 'reference'));

        $this->actingAs(User::factory()->role('observer')->create());
        $this->assertSame([], $this->getJson('/alerts')->json('items'));
        $this->postJson('/results/RS1/acknowledge')->assertForbidden();
        $this->get('/')->assertDontSee('data-alerts=', false);
    }
}
