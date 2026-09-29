<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Ec8aPhoto;
use App\Models\Incident;
use App\Models\PollingUnit;
use App\Models\Result;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class AgentPortalTest extends TestCase
{
    use RefreshDatabase, SendsUssdEvents;

    private const PHONE = '+2348012345678';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        PollingUnit::query()->create(['code' => '21202633007', 'name' => 'Polling Unit 21202633007', 'ward' => 'Abakaliki Ward 01', 'lga' => 'Abakaliki', 'registered_voters' => 1507]);
    }

    private function agentData(array $overrides = []): array
    {
        return array_replace(['id' => 7, 'name' => 'Ada Obi', 'phone_number' => self::PHONE, 'polling_unit' => $this->unit(), 'has_pin' => true, 'locked' => false, 'last_seen_at' => null], $overrides);
    }

    private function signIn(): User
    {
        Agent::query()->create(['ussd_id' => 7, 'name' => 'Ada Obi', 'phone_number' => self::PHONE, 'polling_unit_code' => '21202633007']);
        $user = User::factory()->agent(self::PHONE)->create(['name' => 'Ada Obi']);
        $this->actingAs($user);

        return $user;
    }

    public function test_an_agent_signs_in_with_phone_and_ussd_pin(): void
    {
        Http::fake(['ussd.test/api/agents/verify-pin' => Http::response(['agent' => $this->agentData()])]);

        $this->post('/login/agent', ['phone' => '0801 234 5678', 'pin' => '1234'])->assertRedirect('/field');

        Http::assertSent(fn (Request $request) => $request['phone_number'] === self::PHONE && $request['pin'] === '1234' && $request->hasHeader('Authorization', 'Bearer api-token'));
        $user = User::query()->where('phone', self::PHONE)->sole();
        $this->assertSame('agent', $user->role);
        $this->assertNull($user->email);
        $this->assertAuthenticatedAs($user);
        $this->assertSame('21202633007', Agent::query()->sole()->polling_unit_code);

        // Agents land on their own pages and can't open the situation room.
        $this->get('/')->assertRedirect('/field');
        $this->get('/field')->assertOk()->assertSee('Hello, Ada')->assertSee('Polling Unit 21202633007')->assertDontSee('Dashboard</span>', false);
        foreach (['/incidents', '/monitor', '/agents', '/users', '/collation'] as $page) {
            $this->get($page)->assertForbidden();
        }
    }

    public function test_wrong_pins_and_lockouts_come_from_the_ussd_service(): void
    {
        Http::fake(['ussd.test/api/agents/verify-pin' => Http::sequence()
            ->push(['error' => 'wrong_pin', 'message' => 'Wrong PIN. 2 tries left.', 'tries_left' => 2], 401)
            ->push(['error' => 'locked', 'message' => 'Too many wrong PINs. Try again in 30 minutes.'], 423)]);

        $this->from('/login?as=agent')->post('/login/agent', ['phone' => '08012345678', 'pin' => '0000'])->assertSessionHasErrors(['phone' => 'Wrong PIN. 2 tries left.']);
        $this->from('/login?as=agent')->post('/login/agent', ['phone' => '08012345678', 'pin' => '0000'])->assertSessionHasErrors(['phone' => 'Too many wrong PINs. Try again in 30 minutes.']);
        $this->post('/login/agent', ['phone' => '123', 'pin' => '0000'])->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_a_staff_phone_number_cannot_be_used_to_sign_in_as_an_agent(): void
    {
        User::factory()->admin()->create(['phone' => self::PHONE]);
        Http::fake(['ussd.test/api/agents/verify-pin' => Http::response(['agent' => $this->agentData()])]);

        $this->post('/login/agent', ['phone' => self::PHONE, 'pin' => '1234'])->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_submitting_a_result_with_a_photo_and_a_video(): void
    {
        $this->signIn();
        $record = $this->resultPayload(['reference' => 'RS100001', 'channel' => 'web', 'submitted_at' => now()->toIso8601String()]);
        Http::fake(['ussd.test/api/field/results' => Http::response(['message' => 'Result submitted. Ref: RS100001', 'result' => $record], 201)]);

        $this->get('/field/result')->assertOk()->assertSee('Submit result')->assertSee('OTHERS');

        $this->postJson('/field/result', [
            'accredited_voters' => 1200,
            'votes' => ['APC' => 610, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18],
            'rejected_votes' => 21,
            'media' => [UploadedFile::fake()->image('ec8a.jpg', 1200, 1600), UploadedFile::fake()->create('clip.mp4', 3000, 'video/mp4')],
        ])->assertCreated()->assertJsonPath('reference', 'RS100001')->assertJsonPath('url', route('field.history'));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/field/results')
            && $request['phone_number'] === self::PHONE && $request['votes']['APC'] == 610 && $request['correction'] == 0);

        $result = Result::query()->where('reference', 'RS100001')->sole();
        $this->assertSame('web', $result->channel);
        $this->assertSame(1, Ec8aPhoto::query()->where('result_reference', 'RS100001')->count());
        $this->assertSame('video', Attachment::query()->where('reference', 'RS100001')->sole()->kind);

        $this->get('/field/history')->assertOk()->assertSee('RS100001')->assertSee('Web app')->assertSee('2 files attached');
        $this->get('/field/result')->assertSee('Send a correction');
    }

    public function test_ussd_refusals_are_shown_and_nothing_is_stored(): void
    {
        $this->signIn();
        Http::fake(['ussd.test/api/field/results' => Http::response(['error' => 'over_accredited', 'message' => 'Votes cast (2000) are more than accredited voters (1200). Check the figures.'], 422)]);

        $this->postJson('/field/result', ['accredited_voters' => 1200, 'votes' => ['APC' => 2000, 'PDP' => 0, 'LP' => 0, 'OTHERS' => 0], 'rejected_votes' => 0, 'media' => [UploadedFile::fake()->image('a.jpg')]])
            ->assertStatus(422)->assertJsonPath('message', 'Votes cast (2000) are more than accredited voters (1200). Check the figures.')->assertJsonPath('retry', false);

        $this->assertSame(0, Result::query()->count() + Ec8aPhoto::query()->count());

        // Our own validation first: every party's votes are required.
        $this->postJson('/field/result', ['accredited_voters' => 10, 'votes' => ['APC' => 1], 'rejected_votes' => 0])->assertJsonValidationErrors('votes.PDP');
    }

    public function test_an_unreachable_ussd_service_asks_the_phone_to_retry(): void
    {
        $this->signIn();
        Http::fake(['ussd.test/*' => fn () => throw new ConnectionException('down')]);

        $this->postJson('/field/incident', ['type' => 'violence', 'note' => 'Thugs at the PU'])->assertStatus(503)->assertJsonPath('retry', true);
    }

    public function test_reporting_an_incident_with_a_video_and_adding_more_later(): void
    {
        $this->signIn();
        $record = ['reference' => 'IN200001', 'polling_unit' => $this->unit(), 'type' => 'violence', 'type_label' => 'Violence', 'urgent' => true, 'note' => 'Thugs chased voters', 'agent' => ['name' => 'Ada Obi', 'phone_number' => self::PHONE], 'channel' => 'web', 'reported_at' => now()->toIso8601String(), 'rehearsal' => false];
        Http::fake(['ussd.test/api/field/incidents' => Http::response(['message' => 'Incident logged. Ref: IN200001', 'incident' => $record], 201)]);

        $this->get('/field/incident')->assertOk()->assertSee('Vote buying')->assertSee('Photos or videos (optional)');
        $this->post('/field/incident', ['type' => 'violence', 'note' => 'Thugs chased voters', 'media' => [UploadedFile::fake()->create('fight.mov', 5000, 'video/quicktime')]])
            ->assertRedirect('/field/history')->assertSessionHas('status', 'Incident logged. Ref: IN200001. With 1 video.');

        $this->assertSame('web', Incident::query()->sole()->channel);
        $this->post('/field/media/IN200001', ['media' => [UploadedFile::fake()->image('after.jpg')]])->assertRedirect('/field/history');
        $this->assertSame(['image', 'video'], Attachment::query()->orderBy('kind')->pluck('kind')->all());

        // Only to your own reports, and within the size limit.
        $this->post('/field/media/IN999999', ['media' => [UploadedFile::fake()->image('x.jpg')]])->assertNotFound();
        config(['election.media.max_video_mb' => 1]);
        $this->postJson('/field/media/IN200001', ['media' => [UploadedFile::fake()->create('long.mp4', 5000, 'video/mp4')]])->assertJsonValidationErrors('media.0');
        $this->postJson('/field/media/IN200001', ['media' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]])->assertJsonValidationErrors('media.0');
    }

    public function test_check_in_and_materials(): void
    {
        $this->signIn();
        Http::fake([
            'ussd.test/api/field/presence' => Http::response(['message' => 'Presence confirmed.', 'presence' => ['id' => 3, 'polling_unit' => $this->unit(), 'agent' => ['name' => 'Ada Obi', 'phone_number' => self::PHONE], 'channel' => 'web', 'confirmed_at' => now()->toIso8601String()]], 201),
            'ussd.test/api/field/materials' => Http::response(['message' => 'Materials report saved.', 'materials' => ['id' => 4, 'polling_unit' => $this->unit(), 'status' => 'incomplete', 'status_label' => 'Arrived (incomplete)', 'agent' => ['name' => 'Ada Obi', 'phone_number' => self::PHONE], 'channel' => 'web', 'reported_at' => now()->toIso8601String()]], 201),
        ]);

        $this->post('/field/presence')->assertSessionHasErrors(['latitude' => 'Your location is needed to check in. Allow location for this site and try again.']);
        Http::assertNothingSent();
        $this->post('/field/presence', ['latitude' => 6.3249, 'longitude' => 8.1137, 'location_accuracy' => 12, 'located_at' => now()->getTimestampMs()])->assertRedirect('/field');
        $this->post('/field/materials', ['status' => 'incomplete'])->assertRedirect('/field');
        $this->post('/field/materials', ['status' => 'lost'])->assertSessionHasErrors('status');

        $this->get('/field')->assertSee('Arrived (incomplete)')->assertSee('Check in again');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/field/materials') && $request['status'] === 'incomplete');
    }

    public function test_staff_add_agents_and_reset_pins_through_the_ussd_service(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Coord One']));
        Http::fake([
            'ussd.test/api/agents/reset-pin' => Http::response(['agent' => $this->agentData(['id' => 9, 'phone_number' => '+2348032223333', 'name' => 'Chika Eze']), 'pin' => '4821']),
            'ussd.test/api/agents' => Http::response(['agent' => $this->agentData(['id' => 9, 'phone_number' => '+2348032223333', 'name' => 'Chika Eze']), 'pin' => '5930', 'created' => true], 201),
        ]);

        $this->get('/agents')->assertOk()->assertSee('Add an agent');
        $this->post('/agents', ['name' => 'Chika Eze', 'phone_number' => '0803 222 3333', 'polling_unit' => '21202633007'])
            ->assertSessionHas('status', 'Added Chika Eze. Their PIN is 5930: give it to them privately (it is not shown again).');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/agents') && $request['by'] === 'Coord One' && $request['sms_pin'] == 0);

        $agent = Agent::query()->where('ussd_id', 9)->sole();
        $this->post("/agents/{$agent->id}/reset-pin", ['sms_pin' => 1])->assertSessionHas('status', 'New PIN set for Chika Eze (and unlocked). Their PIN was sent to them by SMS.');

        // Observers can't.
        $this->actingAs(User::factory()->role('observer')->create());
        $this->post('/agents', ['name' => 'X', 'phone_number' => '08030000000'])->assertForbidden();
    }

    public function test_staff_without_a_phone_do_not_get_the_agent_pages(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/')->assertOk()->assertDontSee('My reports');
    }
}
