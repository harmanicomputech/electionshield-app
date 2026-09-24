<?php

namespace Tests\Feature;

use App\Enums\ResultStatus;
use App\Models\Incident;
use App\Models\MaterialReport;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\Result;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;
    use SendsUssdEvents;

    public function test_a_signed_result_is_stored(): void
    {
        $this->sendEvent('result.submitted', $this->resultPayload())
            ->assertOk()
            ->assertJson(['status' => 'stored']);

        $result = Result::where('reference', 'RS784321')->firstOrFail();
        $this->assertSame(ResultStatus::Accepted, $result->status);
        $this->assertSame('Abakaliki', $result->lga);
        $this->assertSame(['APC' => 610, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18], $result->votesByParty());
        $this->assertSame(1125, $result->total_valid_votes);
        $this->assertSame('2027-02-06 15:04:10', $result->submitted_at->format('Y-m-d H:i:s'));
        $this->assertSame(1507, PollingUnit::where('code', '21202633007')->value('registered_voters'));
        $this->assertNotNull(WebhookEvent::first()->processed_at);
    }

    public function test_times_with_an_offset_are_stored_in_utc(): void
    {
        $this->sendEvent('result.submitted', $this->resultPayload(['submitted_at' => '2027-02-06T16:04:10+01:00']))->assertOk();

        $this->assertSame('2027-02-06 15:04:10', Result::first()->submitted_at->format('Y-m-d H:i:s'));
    }

    public function test_a_wrong_signature_is_refused(): void
    {
        $this->sendEvent('result.submitted', $this->resultPayload(), secret: 'wrong')->assertUnauthorized();
        $this->sendEvent('result.submitted', $this->resultPayload(), secret: null)->assertUnauthorized();

        $this->assertSame(0, Result::count());
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_a_wrong_or_missing_token_is_refused(): void
    {
        $this->sendEvent('result.submitted', $this->resultPayload(), token: 'wrong')->assertUnauthorized();
        $this->sendEvent('result.submitted', $this->resultPayload(), token: null)->assertUnauthorized();

        $this->assertSame(0, Result::count());
    }

    public function test_events_are_refused_until_the_webhook_is_configured(): void
    {
        config(['services.ussd.webhook_secret' => null]);

        $this->sendEvent('result.submitted', $this->resultPayload())->assertStatus(503);
        $this->assertSame(0, Result::count());
    }

    public function test_the_same_event_twice_is_applied_once(): void
    {
        $this->sendEvent('result.submitted', $this->resultPayload())->assertOk();
        $this->sendEvent('result.submitted', $this->resultPayload())->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, Result::count());
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_an_approved_correction_supersedes_the_original(): void
    {
        $this->sendEvent('result.submitted', $this->resultPayload(['reference' => 'RS1']))->assertOk();
        $this->sendEvent('result.correction_requested', $this->resultPayload(['reference' => 'RS2', 'status' => 'pending', 'corrects_reference' => 'RS1', 'votes' => ['APC' => 600, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18]]))->assertOk();

        $this->assertSame(ResultStatus::Accepted, Result::where('reference', 'RS1')->value('status'));
        $this->assertSame(1, Result::counted(false)->count());

        $this->sendEvent('result.corrected', $this->resultPayload(['reference' => 'RS2', 'status' => 'accepted', 'corrects_reference' => 'RS1', 'superseded_reference' => 'RS1', 'reviewed_by' => 'Coordinator', 'votes' => ['APC' => 600, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18]]))->assertOk();

        $this->assertSame(ResultStatus::Superseded, Result::where('reference', 'RS1')->value('status'));
        $this->assertSame(['RS2'], Result::counted(false)->pluck('reference')->all());
        $this->assertSame('Coordinator', Result::where('reference', 'RS2')->value('reviewed_by'));
    }

    public function test_a_correction_that_arrives_before_the_original_still_supersedes_it(): void
    {
        $this->sendEvent('result.corrected', $this->resultPayload(['reference' => 'RS2', 'corrects_reference' => 'RS1', 'superseded_reference' => 'RS1']))->assertOk();
        $this->sendEvent('result.submitted', $this->resultPayload(['reference' => 'RS1']))->assertOk();

        $this->assertSame(ResultStatus::Superseded, Result::where('reference', 'RS1')->value('status'));
        $this->assertSame(['RS2'], Result::counted(false)->pluck('reference')->all());
    }

    public function test_a_late_event_never_moves_a_result_back(): void
    {
        $this->sendEvent('result.correction_rejected', $this->resultPayload(['reference' => 'RS2', 'status' => 'rejected', 'corrects_reference' => 'RS1']))->assertOk();
        $this->sendEvent('result.correction_requested', $this->resultPayload(['reference' => 'RS2', 'status' => 'pending', 'corrects_reference' => 'RS1']))->assertOk();

        $this->assertSame(ResultStatus::Rejected, Result::where('reference', 'RS2')->value('status'));
    }

    public function test_a_rejected_correction_leaves_the_original_counted(): void
    {
        $this->sendEvent('result.submitted', $this->resultPayload(['reference' => 'RS1']))->assertOk();
        $this->sendEvent('result.correction_rejected', $this->resultPayload(['reference' => 'RS2', 'status' => 'rejected', 'corrects_reference' => 'RS1']))->assertOk();

        $this->assertSame(['RS1'], Result::counted(false)->pluck('reference')->all());
    }

    public function test_incidents_materials_and_presence_are_stored(): void
    {
        $agent = ['name' => 'Ada Obi', 'phone_number' => '+2348012345678'];

        $this->sendEvent('incident.reported', ['reference' => 'IN1001', 'polling_unit' => $this->unit(), 'type' => 'violence', 'type_label' => 'Violence', 'urgent' => true, 'note' => 'Thugs at the PU', 'agent' => $agent, 'reported_at' => '2027-02-06T10:00:00+00:00', 'rehearsal' => false])->assertOk();
        $this->sendEvent('materials.reported', ['id' => 7, 'polling_unit' => $this->unit(), 'status' => 'arrived', 'status_label' => 'Arrived', 'agent' => $agent, 'reported_at' => '2027-02-06T07:30:00+00:00', 'rehearsal' => false])->assertOk();
        $this->sendEvent('presence.confirmed', ['id' => 3, 'polling_unit' => $this->unit(), 'agent' => $agent, 'confirmed_at' => '2027-02-06T07:10:00+00:00', 'rehearsal' => true])->assertOk();

        $incident = Incident::firstOrFail();
        $this->assertTrue($incident->urgent);
        $this->assertSame('Abakaliki', $incident->lga);
        $this->assertSame('arrived', MaterialReport::where('ussd_id', 7)->value('status'));
        $this->assertTrue(Presence::where('ussd_id', 3)->firstOrFail()->rehearsal);
    }

    public function test_an_unknown_event_is_stored_and_acknowledged(): void
    {
        $this->sendEvent('agent.renamed', ['id' => 1])->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame('agent.renamed', WebhookEvent::firstOrFail()->event);
    }

    public function test_an_event_that_cannot_be_applied_is_kept_and_retried(): void
    {
        $this->sendEvent('result.submitted', $this->resultPayload(['reference' => null]), key: 'result.submitted:BAD')->assertStatus(500);

        $event = WebhookEvent::firstOrFail();
        $this->assertNull($event->processed_at);
        $this->assertNotNull($event->error);

        // The retry finds the stored event and tries again.
        $this->sendEvent('result.submitted', $this->resultPayload(['reference' => null]), key: 'result.submitted:BAD')->assertStatus(500);
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_rehearsal_results_are_kept_apart(): void
    {
        $this->sendEvent('result.submitted', $this->resultPayload(['rehearsal' => true]))->assertOk();

        $this->assertSame(0, Result::counted(false)->count());
        $this->assertSame(1, Result::counted(true)->count());
    }
}
