<?php

namespace Tests\Feature;

use App\Http\Controllers\Console\BroadcastController;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Broadcast;
use App\Models\BroadcastMessage;
use App\Models\Contact;
use App\Models\OptOut;
use App\Models\User;
use App\Services\Broadcasting\Audience;
use App\Services\UssdIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use RefreshDatabase;
    use SendsUssdEvents;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.africastalking' => ['username' => 'electionshield', 'api_key' => 'at-key', 'sender_id' => 'SHIELD', 'callback_secret' => 'cb-secret'],
            'services.whatsapp' => ['phone_number_id' => '1234', 'token' => 'wa-token', 'app_secret' => 'wa-secret', 'verify_token' => 'wa-verify', 'api_version' => 'v21.0'],
        ]);

        $ingestor = app(UssdIngestor::class);
        $ingestor->pollingUnit($this->unit('21202633001', 'Abakaliki', 'Ward A'));
        $ingestor->pollingUnit($this->unit('21302633001', 'Afikpo North', 'Ward C'));
        $ingestor->agent(['id' => 1, 'name' => 'Agent Ada', 'phone_number' => '08030000001', 'polling_unit' => ['code' => '21202633001']]);
        $ingestor->agent(['id' => 2, 'name' => 'Agent Bayo', 'phone_number' => '+2348030000002', 'polling_unit' => ['code' => '21302633001']]);

        Contact::create(['name' => 'Supporter In', 'phone' => '+2348031111111', 'type' => 'supporter', 'lga' => 'Abakaliki', 'ward' => 'Ward A', 'sms_opt_in_at' => now(), 'whatsapp_opt_in_at' => now()]);
        Contact::create(['name' => 'Supporter No Consent', 'phone' => '+2348032222222', 'type' => 'supporter', 'lga' => 'Abakaliki']);
        Contact::create(['name' => 'Supporter Afikpo', 'phone' => '+2348033333333', 'type' => 'supporter', 'lga' => 'Afikpo North', 'sms_opt_in_at' => now()]);
        Contact::create(['name' => 'Coordinator', 'phone' => '+2348034444444', 'type' => 'coordinator', 'lga' => 'Abakaliki']);
        // The same person as agent 1: sent once.
        Contact::create(['name' => 'Ada again', 'phone' => '+2348030000001', 'type' => 'supporter', 'lga' => 'Abakaliki', 'sms_opt_in_at' => now()]);
    }

    private function phones(array $audience, string $channel = 'sms'): array
    {
        return app(Audience::class)->recipients($audience, $channel)->keys()->sort()->values()->all();
    }

    private function fakeAfricasTalking(array $failing = []): void
    {
        Http::fake(['api.africastalking.com/*' => function (Request $request) use ($failing) {
            $recipients = array_map(fn ($number) => in_array($number, $failing, true)
                ? ['statusCode' => 406, 'number' => $number, 'status' => 'UserInBlacklist', 'cost' => '0', 'messageId' => 'None']
                : ['statusCode' => 101, 'number' => $number, 'status' => 'Success', 'cost' => 'NGN 2.2000', 'messageId' => 'ATXid_'.substr($number, -4)], explode(',', $request['to']));

            return Http::response(['SMSMessageData' => ['Message' => 'Sent', 'Recipients' => $recipients]]);
        }]);
    }

    private function draft(array $overrides = []): Broadcast
    {
        return Broadcast::create(array_replace([
            'title' => 'Polls open at 8am',
            'channel' => 'sms',
            'message' => 'Polls open at 8:30am on Saturday. Bring your PVC.',
            'audience' => ['groups' => ['supporters', 'agents', 'coordinators']],
            'status' => 'draft',
            'created_by' => 'Admin',
        ], $overrides));
    }

    public function test_the_audience_follows_consent_opt_outs_and_area(): void
    {
        $this->assertSame(['+2348030000001', '+2348031111111', '+2348033333333'], $this->phones(['groups' => ['supporters']]));
        $this->assertSame(['+2348030000001', '+2348030000002'], $this->phones(['groups' => ['agents']]));
        $this->assertSame(['+2348034444444'], $this->phones(['groups' => ['coordinators']]));

        // Everyone in Abakaliki, each number once.
        $this->assertSame(['+2348030000001', '+2348031111111', '+2348034444444'], $this->phones(['groups' => ['supporters', 'agents', 'coordinators'], 'lgas' => ['Abakaliki']]));
        $this->assertSame(['+2348030000001', '+2348031111111'], $this->phones(['groups' => ['supporters', 'agents'], 'wards' => ['Abakaliki|Ward A']]));

        // WhatsApp needs a WhatsApp opt-in; agents never get it.
        $this->assertSame(['+2348031111111'], $this->phones(['groups' => ['supporters', 'agents', 'coordinators']], 'whatsapp'));

        OptOut::record('+2348031111111', 'sms', 'test');
        OptOut::record('+2348030000002', 'sms', 'test');
        $this->assertSame(['+2348030000001', '+2348033333333'], $this->phones(['groups' => ['supporters']]));
        $this->assertSame(['+2348030000001'], $this->phones(['groups' => ['agents']]));
        $this->assertSame(['+2348031111111'], $this->phones(['groups' => ['supporters']], 'whatsapp'));
    }

    public function test_an_sms_broadcast_is_confirmed_sent_in_batches_and_reported(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name' => 'Kehinde']));
        $this->fakeAfricasTalking(failing: ['+2348034444444']);
        $broadcast = $this->draft();

        $this->get("/broadcasts/{$broadcast->id}")->assertOk()->assertSee('5</b> recipients', false)->assertSee('1 SMS part each');
        $this->post("/broadcasts/{$broadcast->id}/send", ['confirm' => 4])->assertSessionHasErrors('confirm');
        $this->assertSame(0, BroadcastMessage::count());

        $this->post("/broadcasts/{$broadcast->id}/send", ['confirm' => 5])->assertSessionHas('status');

        $broadcast->refresh();
        $this->assertSame('sent', $broadcast->status);
        $this->assertSame('Kehinde', $broadcast->sent_by);
        $this->assertSame(['failed' => 1, 'sent' => 4], collect($broadcast->counts())->sortKeys()->all());
        $this->assertSame('UserInBlacklist', BroadcastMessage::where('phone', '+2348034444444')->value('failure_reason'));

        Http::assertSent(fn (Request $request) => $request['bulkSMSMode'] == 1 && $request['from'] === 'SHIELD' && $request->header('apiKey')[0] === 'at-key' && substr_count($request['to'], ',') === 4);

        // Delivery report.
        $this->post('/api/sms/delivery/cb-secret', ['id' => 'ATXid_1111', 'status' => 'Success', 'phoneNumber' => '+2348031111111'])->assertOk();
        $this->post('/api/sms/delivery/cb-secret', ['id' => 'ATXid_3333', 'status' => 'Failed', 'failureReason' => 'DeliveryFailure'])->assertOk();
        $this->post('/api/sms/delivery/wrong', ['id' => 'ATXid_0001', 'status' => 'Success'])->assertNotFound();

        $this->assertSame('delivered', BroadcastMessage::where('provider_id', 'ATXid_1111')->value('status'));
        $this->assertSame('DeliveryFailure', BroadcastMessage::where('provider_id', 'ATXid_3333')->value('failure_reason'));
        $this->get("/broadcasts/{$broadcast->id}")->assertSee('Delivered (20%)');

        // Sending again is refused.
        $this->post("/broadcasts/{$broadcast->id}/send", ['confirm' => 5])->assertStatus(409);
        $this->assertTrue(AuditLog::where('action', 'broadcast.sent')->exists());
    }

    public function test_opt_outs_from_africas_talking_are_honoured(): void
    {
        $this->post('/api/sms/opt-out/cb-secret', ['senderId' => 'SHIELD', 'phoneNumber' => '+2348031111111'])->assertOk();
        $this->post('/api/sms/opt-out/cb-secret', ['senderId' => 'SHIELD', 'phoneNumber' => '+2348031111111'])->assertOk();

        $this->assertSame(1, OptOut::count());
        $this->assertNotContains('+2348031111111', $this->phones(['groups' => ['supporters']]));
    }

    public function test_a_whatsapp_template_broadcast_and_its_webhook(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]])]);
        $broadcast = $this->draft(['channel' => 'whatsapp', 'template' => 'election_update', 'template_language' => 'en', 'message' => "{name}\nSaturday 8:30am", 'audience' => ['groups' => ['supporters']]]);

        $this->post("/broadcasts/{$broadcast->id}/send", ['confirm' => 1])->assertSessionHas('status');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v21.0/1234/messages')
            && $request['to'] === '2348031111111'
            && $request['template']['name'] === 'election_update'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Supporter In'
            && $request->header('Authorization')[0] === 'Bearer wa-token');
        $this->assertSame('sent', BroadcastMessage::sole()->status);

        $body = json_encode(['entry' => [['changes' => [['value' => [
            'statuses' => [['id' => 'wamid.ABC', 'status' => 'delivered']],
            'messages' => [['from' => '2348031111111', 'text' => ['body' => 'stop']]],
        ]]]]]]);
        $sign = fn ($secret) => ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret), 'Content-Type' => 'application/json'];

        $this->call('POST', '/api/whatsapp', [], [], [], $this->transformHeadersToServerVars($sign('wrong')), $body)->assertUnauthorized();
        $this->call('POST', '/api/whatsapp', [], [], [], $this->transformHeadersToServerVars($sign('wa-secret')), $body)->assertOk();

        $this->assertSame('delivered', BroadcastMessage::sole()->status);
        $this->assertTrue(OptOut::where(['phone' => '+2348031111111', 'channel' => 'whatsapp'])->exists());

        $this->get('/api/whatsapp?hub.mode=subscribe&hub.verify_token=wa-verify&hub.challenge=12345')->assertOk()->assertSeeText('12345');
        $this->get('/api/whatsapp?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=1')->assertForbidden();
    }

    public function test_scheduled_broadcasts_go_out_on_time(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->fakeAfricasTalking();
        $broadcast = $this->draft(['audience' => ['groups' => ['coordinators']]]);

        $this->post("/broadcasts/{$broadcast->id}/schedule", ['scheduled_at' => now()->subHour()->setTimezone('Africa/Lagos')->format('Y-m-d\TH:i')])->assertSessionHas('error');
        $this->post("/broadcasts/{$broadcast->id}/schedule", ['scheduled_at' => now()->addHour()->setTimezone('Africa/Lagos')->format('Y-m-d\TH:i')])->assertSessionHas('status');
        $this->assertSame('scheduled', $broadcast->fresh()->status);

        $this->artisan('broadcast:dispatch');
        $this->assertSame('scheduled', $broadcast->fresh()->status);

        $this->travel(61)->minutes();
        $this->artisan('broadcast:dispatch');
        $this->assertSame('sent', $broadcast->fresh()->status);
        $this->assertSame(1, BroadcastMessage::count());
    }

    public function test_a_test_send_leaves_no_trace_and_drafts_can_be_cancelled(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->fakeAfricasTalking();
        $broadcast = $this->draft();

        $this->post("/broadcasts/{$broadcast->id}/test", ['phone' => '0809 999 9999'])->assertSessionHas('status', 'Test sent to +2348099999999.');
        $this->assertSame(0, BroadcastMessage::count());
        Http::assertSent(fn (Request $request) => $request['to'] === '+2348099999999');

        $this->post("/broadcasts/{$broadcast->id}/cancel")->assertSessionHas('status');
        $this->assertSame('cancelled', $broadcast->fresh()->status);
    }

    public function test_drafts_are_created_and_validated(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/broadcasts', ['title' => 'x', 'channel' => 'whatsapp', 'groups' => ['supporters'], 'template' => 'Bad Name'])->assertSessionHasErrors('template');
        $this->post('/broadcasts', ['title' => 'x', 'channel' => 'sms', 'groups' => []])->assertSessionHasErrors(['message', 'groups']);

        $this->post('/broadcasts', ['title' => 'Reminder', 'channel' => 'sms', 'message' => 'Vote!', 'groups' => ['supporters'], 'lgas' => ['Abakaliki']])->assertRedirect();
        $this->assertSame(['groups' => ['supporters'], 'lgas' => ['Abakaliki'], 'wards' => []], Broadcast::sole()->audience);
        $this->get('/broadcasts/create')->assertOk();
    }

    public function test_sms_parts_are_counted(): void
    {
        $this->assertSame(['characters' => 160, 'segments' => 1, 'unicode' => false], BroadcastController::segments(str_repeat('a', 160)));
        $this->assertSame(2, BroadcastController::segments(str_repeat('a', 161))['segments']);
        // € is an extended GSM character: it takes two.
        $this->assertSame(['characters' => 4, 'segments' => 1, 'unicode' => false], BroadcastController::segments('a€b'));
        $this->assertTrue(BroadcastController::segments('Vote 🗳️')['unicode']);
        $this->assertSame(2, BroadcastController::segments(str_repeat('é', 50).'👍'.str_repeat('x', 20))['segments']);
    }

    public function test_people_join_with_explicit_consent(): void
    {
        $this->get('/join')->assertOk()->assertSee('Get election updates')->assertSee('Abakaliki');

        $this->post('/join', ['name' => 'New', 'phone' => '0805 555 5555', 'sms' => 1])->assertSessionHasErrors('consent');
        $this->post('/join', ['name' => 'Bot', 'phone' => '08055555555', 'sms' => 1, 'consent' => 1, 'website' => 'spam'])->assertRedirect();
        $this->assertNull(Contact::where('phone', '+2348055555555')->first());

        OptOut::record('+2348055555555', 'sms', 'earlier');
        $this->post('/join', ['name' => 'New', 'phone' => '0805 555 5555', 'lga' => 'Abakaliki', 'sms' => 1, 'consent' => 1])->assertRedirect('/join');

        $contact = Contact::where('phone', '+2348055555555')->sole();
        $this->assertNotNull($contact->sms_opt_in_at);
        $this->assertNull($contact->whatsapp_opt_in_at);
        $this->assertSame('join form', $contact->source);
        $this->assertFalse(OptOut::where('phone', '+2348055555555')->exists());
    }

    public function test_admins_import_contacts_and_coordinators_cannot_broadcast(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $csv = "name,phone,type,lga,ward,sms_opt_in,whatsapp_opt_in\nNew One,08060000001,supporter,Ezza North,,yes,no\nBad,123,supporter,,,yes,\nSupporter No Consent,08032222222,,,,no,yes\n";
        $this->post('/contacts/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', $csv)])->assertSessionHas('import_errors', fn ($e) => count($e) === 1);

        $this->assertNotNull(Contact::where('phone', '+2348060000001')->value('sms_opt_in_at'));
        $existing = Contact::where('phone', '+2348032222222')->sole();
        $this->assertNull($existing->sms_opt_in_at);
        $this->assertNotNull($existing->whatsapp_opt_in_at);
        $this->get('/contacts')->assertOk()->assertSee('New One');

        $this->actingAs(User::factory()->create());
        $this->get('/broadcasts')->assertForbidden();
        $this->get('/contacts')->assertForbidden();
    }

    public function test_agents_keep_their_normalised_numbers(): void
    {
        $this->assertSame('08030000001', Agent::where('ussd_id', 1)->value('phone_number'));
        $this->assertContains('+2348030000001', $this->phones(['groups' => ['agents']]));
    }
}
