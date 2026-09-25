<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushNotifier;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;
    use SendsUssdEvents;

    /** @var list<array{topic: string, message: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Settings::flush();

        $sent = &$this->sent;
        $this->app->instance(PushNotifier::class, new class($sent) extends PushNotifier
        {
            public function __construct(private array &$sent) {}

            public function toTopic(string $topic, array $message): int
            {
                $this->sent[] = compact('topic', 'message');

                return 1;
            }
        });
    }

    private function incident(array $overrides = []): array
    {
        return array_replace([
            'reference' => 'IN1',
            'polling_unit' => $this->unit(),
            'type' => 'violence',
            'type_label' => 'Violence',
            'urgent' => true,
            'note' => 'Thugs at the PU',
            'agent' => ['name' => 'Ada Obi', 'phone_number' => '+2348012345678'],
            'reported_at' => now()->subMinute()->toIso8601String(),
            'rehearsal' => false,
        ], $overrides);
    }

    private function subscription(array $overrides = []): array
    {
        return array_replace_recursive([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'BPublicKey', 'auth' => 'authSecret'],
            'contentEncoding' => 'aes128gcm',
            'topics' => ['urgent_incidents', 'corrections'],
        ], $overrides);
    }

    public function test_an_urgent_incident_alerts_subscribed_devices_once(): void
    {
        $this->sendEvent('incident.reported', $this->incident())->assertOk();
        $this->sendEvent('incident.reported', $this->incident())->assertOk();

        $this->assertCount(1, $this->sent);
        $this->assertSame('urgent_incidents', $this->sent[0]['topic']);
        $this->assertSame('⚠ Violence reported', $this->sent[0]['message']['title']);
        $this->assertStringContainsString('Polling Unit 21202633007 (Abakaliki › Abakaliki Ward 01): Thugs at the PU', $this->sent[0]['message']['body']);
        $this->assertStringNotContainsString('+234', json_encode($this->sent[0]['message']));
        $this->assertStringEndsWith('#incident-IN1', $this->sent[0]['message']['url']);
        $this->assertTrue($this->sent[0]['message']['urgent']);
    }

    public function test_non_urgent_old_and_rehearsal_incidents(): void
    {
        $this->sendEvent('incident.reported', $this->incident(['reference' => 'IN2', 'type' => 'delay', 'urgent' => false]))->assertOk();
        $this->sendEvent('incident.reported', $this->incident(['reference' => 'IN3', 'reported_at' => now()->subHours(2)->toIso8601String()]))->assertOk();
        $this->sendEvent('incident.reported', $this->incident(['reference' => 'IN5', 'reported_at' => now()->addDay()->toIso8601String()]))->assertOk();
        $this->assertCount(0, $this->sent);

        $this->sendEvent('incident.reported', $this->incident(['reference' => 'IN4', 'rehearsal' => true]))->assertOk();
        $this->assertSame('[Rehearsal] ⚠ Violence reported', $this->sent[0]['message']['title']);
    }

    public function test_a_correction_awaiting_review_alerts(): void
    {
        $this->sendEvent('result.submitted', $this->resultPayload(['reference' => 'RS1', 'submitted_at' => now()->toIso8601String()]))->assertOk();
        $this->assertCount(0, $this->sent);

        $this->sendEvent('result.correction_requested', $this->resultPayload(['reference' => 'RS2', 'status' => 'pending', 'corrects_reference' => 'RS1', 'submitted_at' => now()->toIso8601String()]))->assertOk();

        $this->assertSame('corrections', $this->sent[0]['topic']);
        $this->assertSame('Polling Unit 21202633007: RS2 corrects RS1', $this->sent[0]['message']['body']);
        $this->assertSame('/corrections', $this->sent[0]['message']['url']);
    }

    public function test_devices_opt_in_change_topics_and_opt_out(): void
    {
        app(PushNotifier::class)->generateKeys();
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get('/notifications')->assertOk()->assertSee('Turn on notifications');
        $this->postJson('/push/subscribe', $this->subscription())->assertOk();
        $this->postJson('/push/subscribe', $this->subscription(['topics' => ['corrections']]))->assertOk();

        $subscription = PushSubscription::sole();
        $this->assertSame(['corrections'], $subscription->topics);
        $this->assertSame(1, PushSubscription::forTopic('corrections')->count());
        $this->assertSame(0, PushSubscription::forTopic('urgent_incidents')->count());

        $this->postJson('/push/subscribe', $this->subscription(['topics' => ['everything']]))->assertJsonValidationErrors('topics.0');
        $this->postJson('/push/subscribe', $this->subscription(['endpoint' => 'http://insecure.example/x']))->assertJsonValidationErrors('endpoint');

        // Someone else can't remove it.
        $this->actingAs(User::factory()->create());
        $this->postJson('/push/unsubscribe', ['endpoint' => $subscription->endpoint])->assertOk();
        $this->assertSame(1, PushSubscription::count());

        $this->actingAs($user);
        $this->postJson('/push/unsubscribe', ['endpoint' => $subscription->endpoint])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_logging_out_stops_the_devices_alerts(): void
    {
        app(PushNotifier::class)->generateKeys();
        $this->actingAs(User::factory()->create());
        $this->postJson('/push/subscribe', $this->subscription())->assertOk();

        $this->post('/logout', ['push_endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123'])->assertRedirect('/login');

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_subscribing_needs_the_keys_set_up_first(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/push/subscribe', $this->subscription())->assertStatus(503);
        $this->get('/notifications')->assertSeeText("Notifications aren't set up yet.");
    }

    public function test_keys_are_created_once_from_the_system_page(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/system/push-keys')->assertSessionHas('status');
        $key = Settings::get('vapid_public_key');
        $this->assertSame(87, strlen($key));

        $this->post('/system/push-keys')->assertSessionHas('error');
        Settings::flush();
        $this->assertSame($key, Settings::get('vapid_public_key'));

        $this->get('/')->assertSee('<meta name="es-push-key" content="'.$key.'">', false);
        $this->get('/system')->assertSee('0 devices opted in');
    }
}
