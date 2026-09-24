<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\User;
use App\Services\UssdIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class IncidentTest extends TestCase
{
    use RefreshDatabase;
    use SendsUssdEvents;

    private function incident(string $reference = 'IN1', string $type = 'violence', bool $urgent = true, bool $rehearsal = false): Incident
    {
        return app(UssdIngestor::class)->incident([
            'reference' => $reference,
            'polling_unit' => $this->unit(),
            'type' => $type,
            'type_label' => ucfirst(str_replace('_', ' ', $type)),
            'urgent' => $urgent,
            'note' => 'Thugs at the PU',
            'agent' => ['name' => 'Ada Obi', 'phone_number' => '+2348012345678'],
            'reported_at' => '2027-02-06T10:00:00+00:00',
        ], $rehearsal);
    }

    public function test_the_feed_lists_unresolved_incidents_urgent_first(): void
    {
        $this->actingAs(User::factory()->create());
        $this->incident('IN1', 'delay', urgent: false);
        $this->incident('IN2', 'violence');
        $this->incident('IN3', 'vote_buying', urgent: false)->forceFill(['resolved_at' => now()])->save();
        $this->incident('IN4', 'violence', rehearsal: true);

        $response = $this->get('/incidents')->assertOk()
            ->assertSee('Thugs at the PU')
            ->assertSee('tel:+2348012345678', false)
            ->assertDontSee('IN3')
            ->assertDontSee('IN4');

        $this->assertLessThan(strpos($response->getContent(), 'IN1 ·'), strpos($response->getContent(), 'IN2 ·'));

        $this->get('/incidents?status=resolved')->assertSee('IN3')->assertDontSee('IN2 ·');
        $this->get('/incidents?type=delay')->assertSee('IN1')->assertDontSee('IN2 ·');
        $this->get('/incidents?urgent=1')->assertSee('IN2')->assertDontSee('IN1 ·');
    }

    public function test_acknowledge_then_resolve_and_reopen(): void
    {
        $user = User::factory()->create(['name' => 'Chidi Coordinator']);
        $this->actingAs($user);
        $this->incident();

        $this->post('/incidents/IN1/acknowledge')->assertRedirect();
        $incident = Incident::firstOrFail();
        $this->assertSame(Incident::ACKNOWLEDGED, $incident->responseStatus());
        $this->assertSame('Chidi Coordinator', $incident->acknowledged_by);

        $this->postJson('/incidents/IN1/resolve', ['resolution_note' => 'Police arrived'])
            ->assertOk()
            ->assertJson(['status' => 'resolved']);
        $incident->refresh();
        $this->assertSame('Police arrived', $incident->resolution_note);
        $this->assertSame(Incident::RESOLVED, $incident->responseStatus());

        $this->post('/incidents/IN1/reopen')->assertRedirect();
        $this->assertSame(Incident::ACKNOWLEDGED, $incident->fresh()->responseStatus());

        $this->assertSame(['incident.acknowledged', 'incident.resolved', 'incident.reopened'], AuditLog::query()->where('action', 'like', 'incident.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_replayed_actions_change_nothing(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'First']));
        $this->incident();
        $this->postJson('/incidents/IN1/acknowledge')->assertOk();
        $at = Incident::firstOrFail()->acknowledged_at;

        $this->actingAs(User::factory()->create(['name' => 'Second']));
        $this->travel(5)->minutes();
        $this->postJson('/incidents/IN1/acknowledge')->assertOk();

        $incident = Incident::firstOrFail();
        $this->assertSame('First', $incident->acknowledged_by);
        $this->assertTrue($at->equalTo($incident->acknowledged_at));
        $this->assertSame(1, AuditLog::where('action', 'incident.acknowledged')->count());
    }

    public function test_resolving_directly_also_acknowledges(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Chidi']));
        $this->incident();

        $this->post('/incidents/IN1/resolve')->assertRedirect();

        $incident = Incident::firstOrFail();
        $this->assertSame('Chidi', $incident->acknowledged_by);
        $this->assertNotNull($incident->resolved_at);
    }

    public function test_a_later_sync_keeps_the_response(): void
    {
        $this->actingAs(User::factory()->create());
        $this->incident();
        $this->post('/incidents/IN1/resolve', ['resolution_note' => 'Calm now']);

        $this->incident();

        $this->assertSame('Calm now', Incident::firstOrFail()->resolution_note);
    }

    public function test_guests_cannot_act_on_incidents(): void
    {
        $this->incident();

        $this->post('/incidents/IN1/acknowledge')->assertRedirect('/login');
        $this->postJson('/incidents/IN1/acknowledge')->assertUnauthorized();
        $this->assertNull(Incident::firstOrFail()->acknowledged_at);
    }

    public function test_the_dashboard_warns_about_unacknowledged_urgent_incidents(): void
    {
        $this->actingAs(User::factory()->create());
        $this->incident();

        $this->get('/')->assertSee('1 urgent incident')->assertSee('<span class="count" aria-label="1 urgent open">1</span>', false);

        $this->post('/incidents/IN1/acknowledge');
        $this->get('/')->assertDontSee('1 urgent incident');
    }
}
