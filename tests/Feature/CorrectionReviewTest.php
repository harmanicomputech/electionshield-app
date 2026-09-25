<?php

namespace Tests\Feature;

use App\Enums\ResultStatus;
use App\Models\AuditLog;
use App\Models\Result;
use App\Models\User;
use App\Services\UssdIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class CorrectionReviewTest extends TestCase
{
    use RefreshDatabase;
    use SendsUssdEvents;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $ingestor = app(UssdIngestor::class);
        $ingestor->result($this->resultPayload(['reference' => 'RS1']), false);
        $ingestor->result($this->resultPayload(['reference' => 'RS2', 'status' => 'pending', 'corrects_reference' => 'RS1', 'votes' => ['APC' => 600, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18], 'total_valid_votes' => 1115]), false);
    }

    public function test_the_page_shows_what_the_correction_changes(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/corrections')->assertOk()
            ->assertSee('RS2 corrects RS1')
            ->assertSee('<b>-10</b>', false)
            ->assertSee('Approve');
        $this->get('/')->assertSee('<span class="count pending" aria-label="1 waiting">1</span>', false);
    }

    public function test_approving_goes_through_the_ussd_service_and_updates_our_copy(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Chidi']));
        Http::fake(['ussd.test/api/corrections/RS2/approve' => Http::response(['data' => $this->resultPayload(['reference' => 'RS2', 'status' => 'accepted', 'corrects_reference' => 'RS1', 'votes' => ['APC' => 600, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18], 'reviewed_by' => 'Chidi', 'reviewed_at' => now()->toIso8601String()])])]);

        $this->postJson('/corrections/RS2/approve')->assertOk()->assertJson(['message' => 'Approved RS2: it replaces RS1.']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://ussd.test/api/corrections/RS2/approve' && $request['reviewed_by'] === 'Chidi' && $request->header('Authorization')[0] === 'Bearer api-token');
        $this->assertSame(ResultStatus::Accepted, Result::where('reference', 'RS2')->value('status'));
        $this->assertSame(ResultStatus::Superseded, Result::where('reference', 'RS1')->value('status'));
        $this->assertSame(['RS2'], Result::counted(false)->pluck('reference')->all());
        $this->assertTrue(AuditLog::where('action', 'correction.approved')->exists());
    }

    public function test_rejecting_needs_a_reason_and_keeps_the_original(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['ussd.test/api/corrections/RS2/reject' => Http::response(['data' => $this->resultPayload(['reference' => 'RS2', 'status' => 'rejected', 'corrects_reference' => 'RS1', 'review_note' => 'Sheet shows 610'])])]);

        $this->postJson('/corrections/RS2/reject')->assertJsonValidationErrors('note');
        $this->postJson('/corrections/RS2/reject', ['note' => 'Sheet shows 610'])->assertOk();

        Http::assertSent(fn (Request $request) => $request['note'] === 'Sheet shows 610');
        $this->assertSame(ResultStatus::Rejected, Result::where('reference', 'RS2')->value('status'));
        $this->assertSame(['RS1'], Result::counted(false)->pluck('reference')->all());
    }

    public function test_a_replayed_or_already_decided_review_is_recognised(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake([
            'ussd.test/api/corrections/RS2/approve' => Http::response(['message' => 'Only pending corrections can be reviewed.'], 409),
            'ussd.test/api/corrections/RS2/reject' => Http::response(['message' => 'Only pending corrections can be reviewed.'], 409),
            'ussd.test/api/results/RS2' => Http::response(['data' => $this->resultPayload(['reference' => 'RS2', 'status' => 'accepted', 'corrects_reference' => 'RS1'])]),
        ]);

        $this->postJson('/corrections/RS2/approve')->assertOk()->assertJson(['message' => 'RS2 was already approved.']);
        $this->assertSame(ResultStatus::Superseded, Result::where('reference', 'RS1')->value('status'));

        // Asking to reject what was approved elsewhere is a conflict, not a success.
        $this->postJson('/corrections/RS2/reject', ['note' => 'x'])->assertStatus(409);
    }

    public function test_the_ussd_service_being_down_is_retryable(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['ussd.test/*' => Http::response('Down', 503)]);

        $this->postJson('/corrections/RS2/approve')->assertStatus(502);
        $this->assertSame(ResultStatus::Pending, Result::where('reference', 'RS2')->value('status'));
    }

    public function test_without_the_api_the_page_says_so(): void
    {
        config(['services.ussd.api_token' => null]);
        $this->actingAs(User::factory()->create());

        $this->get('/corrections')->assertSeeText("The USSD service isn't connected");
        $this->postJson('/corrections/RS2/approve')->assertStatus(502);
    }
}
