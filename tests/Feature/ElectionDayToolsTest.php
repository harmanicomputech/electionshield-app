<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Ec8aPhoto;
use App\Models\Incident;
use App\Models\PushSubscription;
use App\Models\Result;
use App\Models\User;
use App\Services\PushNotifier;
use App\Services\UssdIngestor;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;
use ZipArchive;

class ElectionDayToolsTest extends TestCase
{
    use RefreshDatabase;
    use SendsUssdEvents;

    private UssdIngestor $ingestor;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::flush();
        $this->ingestor = app(UssdIngestor::class);

        // Three PUs in Abakaliki; agents at two of them; one checked in with a result.
        foreach (['21202633001', '21202633002', '21202633003'] as $code) {
            $this->ingestor->pollingUnit($this->unit($code, 'Abakaliki', 'Ward A'));
        }
        $this->ingestor->pollingUnit($this->unit('21302633001', 'Afikpo North', 'Ward C'));
        $this->ingestor->agent(['id' => 1, 'name' => 'Ada Obi', 'phone_number' => '+2348011111111', 'polling_unit' => ['code' => '21202633001']]);
        $this->ingestor->agent(['id' => 2, 'name' => 'Bayo Eze', 'phone_number' => '+2348022222222', 'polling_unit' => ['code' => '21202633002']]);
        $this->ingestor->presence(['id' => 1, 'polling_unit' => ['code' => '21202633001'], 'agent' => [], 'confirmed_at' => now()->toIso8601String()], false);
        $this->ingestor->result($this->resultPayload(['reference' => 'RS1', 'polling_unit' => $this->unit('21202633001', 'Abakaliki', 'Ward A')]), false);
    }

    public function test_the_agents_page_lists_who_to_call_and_the_silent_pus(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/agents')->assertOk()->assertSee('Ada Obi')->assertSee('tel:+2348022222222', false)->assertSee('All agents <b>2</b>', false);
        $this->get('/agents?view=no-checkin')->assertSee('Bayo Eze')->assertDontSee('Ada Obi');
        $this->get('/agents?view=no-result')->assertSee('Bayo Eze')->assertDontSee('Ada Obi');
        $this->get('/agents?view=no-agent')->assertSee('No agent registered for this PU')->assertSee('Polling Unit 21202633003');
        $this->get('/agents?q=0802')->assertSee('Bayo Eze')->assertDontSee('Ada Obi');
        $this->get('/agents?lga=Afikpo North&view=no-agent')->assertSee('Polling Unit 21302633001')->assertDontSee('Polling Unit 21202633003');

        $this->get('/agents/export')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create());
        $csv = $this->get('/agents/export?view=no-checkin')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Bayo Eze",+2348022222222', $csv);
        $this->assertStringNotContainsString('Ada Obi', $csv);
    }

    public function test_clearing_rehearsal_data_removes_only_rehearsal_records(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create(['password' => 'long-password']);
        $this->actingAs($admin);
        $this->ingestor->result($this->resultPayload(['reference' => 'RR1', 'polling_unit' => $this->unit('21202633002', 'Abakaliki', 'Ward A')]), true);
        $this->ingestor->incident(['reference' => 'IR1', 'polling_unit' => ['code' => '21202633002'], 'type' => 'violence', 'urgent' => true, 'agent' => [], 'reported_at' => now()->toIso8601String()], true);
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image);
        $this->post('/photos', ['reference' => 'RR1', 'photo' => UploadedFile::fake()->createWithContent('s.jpg', (string) ob_get_clean())]);
        $photo = Ec8aPhoto::sole();
        Settings::set('data_view', 'rehearsal');

        $this->get('/system')->assertSee('1 rehearsal results, 1 incidents');
        $this->post('/system/clear-rehearsal', ['confirm' => 'clear', 'password' => 'long-password'])->assertSessionHasErrors('confirm');
        $this->post('/system/clear-rehearsal', ['confirm' => 'CLEAR', 'password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertSame(2, Result::count());

        $this->post('/system/clear-rehearsal', ['confirm' => 'CLEAR', 'password' => 'long-password'])->assertSessionHas('status', fn ($s) => str_contains($s, '1 results, 1 incidents, 0 check-ins, 0 materials reports, 1 EC8A photos'));

        $this->assertSame(['RS1'], Result::pluck('reference')->all());
        $this->assertSame(0, Incident::count());
        Storage::disk('local')->assertMissing($photo->path);
        Settings::flush();
        $this->assertFalse(Settings::showingRehearsal());
        $this->assertTrue(AuditLog::where('action', 'system.clear_rehearsal')->exists());
    }

    public function test_the_backup_has_every_table_and_no_secrets(): void
    {
        $this->actingAs(User::factory()->admin()->create(['email' => 'boss@example.com']));

        $response = $this->get('/system/backup')->assertOk()->assertHeader('Content-Type', 'application/zip');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));

        foreach (['polling_units', 'agents', 'results', 'result_votes', 'incidents', 'official_results', 'ec8a_photos', 'broadcast_messages', 'town_hall_questions', 'users', 'audit_logs'] as $table) {
            $this->assertNotFalse($zip->locateName("{$table}.csv"), "{$table}.csv missing");
        }

        $users = $zip->getFromName('users.csv');
        $this->assertStringContainsString('boss@example.com', $users);
        $this->assertStringNotContainsString('password', strtok($users, "\n"));
        $this->assertStringNotContainsString('ip_hash', strtok($zip->getFromName('town_hall_questions.csv'), "\n"));
        $this->assertStringContainsString('RS1', $zip->getFromName('results.csv'));
        $this->assertStringContainsString('Rows per table', $zip->getFromName('README.txt'));
        $this->assertFalse($zip->locateName('settings.csv'));
        $this->assertFalse($zip->locateName('push_subscriptions.csv'));

        $this->actingAs(User::factory()->create());
        $this->get('/system/backup')->assertForbidden();
    }

    public function test_the_evidence_pack_and_situation_report(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Chidi']));
        $this->put('/official/pu/21202633001', ['irev_status' => 'uploaded', 'votes' => ['APC' => 510, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18]]);

        $this->get('/evidence/EB/212/02633/001')->assertNotFound();
        $this->get('/evidence/21202633001')->assertOk()
            ->assertSee('evidence pack')
            ->assertSee('EB/212/02633/001')
            ->assertSee('differ by up to 100 votes')
            ->assertSee('-100')
            ->assertSee('Prepared')
            ->assertSee('by Chidi')
            ->assertDontSee('+2348012345678');
        $this->assertTrue(AuditLog::where('action', 'evidence.viewed')->exists());

        $this->get('/sitrep')->assertOk()
            ->assertSee('situation report')
            ->assertSee('1 of 4')
            ->assertSee('Largest differences from IReV')
            ->assertSee('up to 100 votes');
        $this->get('/compare')->assertSee('/evidence/21202633001', false);
    }

    public function test_people_change_their_own_name_and_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1', 'email' => 'me@example.com']);
        $this->actingAs($user);

        $this->get('/account')->assertOk()->assertSee('me@example.com');
        $this->put('/account', ['name' => 'New Name'])->assertSessionHas('status');
        $this->assertSame('New Name', $user->fresh()->name);

        $this->put('/account/password', ['current_password' => 'wrong', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertSessionHasErrors('current_password');
        $this->put('/account/password', ['current_password' => 'old-password-1', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->put('/account/password', ['current_password' => 'old-password-1', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertSessionHas('status');

        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
        $this->assertTrue(AuditLog::where('action', 'account.password')->exists());
    }

    public function test_a_home_lga_sets_default_filters_and_alerts(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $coordinator = User::factory()->create(['email' => 'c@example.com']);
        $this->put("/users/{$coordinator->id}", ['role' => 'coordinator', 'lga' => 'Afikpo North'])->assertRedirect();
        $this->assertSame('Afikpo North', $coordinator->fresh()->lga);
        $this->put("/users/{$coordinator->id}", ['role' => 'coordinator', 'lga' => 'Nowhere'])->assertSessionHasErrors('lga');

        foreach ([['IN1', '21202633001'], ['IN2', '21302633001']] as [$ref, $code]) {
            $this->ingestor->incident(['reference' => $ref, 'polling_unit' => ['code' => $code], 'type' => 'delay', 'urgent' => false, 'note' => "Note {$ref}", 'agent' => [], 'reported_at' => now()->subHours(2)->toIso8601String()], false);
        }

        $this->actingAs($coordinator->fresh());
        $this->get('/incidents')->assertSee('Note IN2')->assertDontSee('Note IN1');
        $this->get('/incidents?lga=all')->assertSee('Note IN1')->assertSee('Note IN2');
        $this->get('/agents?view=no-agent')->assertSee('Polling Unit 21302633001')->assertDontSee('Polling Unit 21202633003');
        $this->get('/')->assertSee('Your LGA: Afikpo North');

        // Urgent-incident alerts: state-wide people and the LGA's own.
        $stateWide = User::factory()->create();
        $other = User::factory()->create(['lga' => 'Ikwo']);
        foreach ([$coordinator, $stateWide, $other] as $i => $user) {
            PushSubscription::create(['user_id' => $user->id, 'endpoint' => "https://push.example/{$i}", 'endpoint_hash' => hash('sha256', (string) $i), 'public_key' => 'k', 'auth_token' => 'a', 'topics' => ['urgent_incidents']]);
        }
        $captured = [];
        $notifier = new class($captured) extends PushNotifier
        {
            public function __construct(private array &$captured) {}

            public function configured(): bool
            {
                return true;
            }

            public function send(array $subscriptions, array $message): int
            {
                $this->captured = array_map(fn ($s) => $s->user_id, $subscriptions);

                return count($subscriptions);
            }
        };

        $notifier->toTopic('urgent_incidents', ['title' => 't', 'body' => 'b', 'url' => '/'], 'Afikpo North');
        sort($captured);
        $this->assertSame([$coordinator->id, $stateWide->id], $captured);
    }
}
