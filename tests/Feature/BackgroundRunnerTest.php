<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\User;
use App\Support\BackgroundRunner;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackgroundRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::flush();
        config(['election.background_runner' => true]);
    }

    private function fakeUssdApi(array &$calls): void
    {
        Http::fake(function (Request $request) use (&$calls) {
            $calls[] = parse_url($request->url(), PHP_URL_PATH);

            return Http::response(['data' => [], 'next_cursor' => null, 'server_time' => now()->toIso8601String(), 'rehearsal_mode' => false]);
        });
    }

    public function test_the_pinger_url_runs_the_work_once_per_sync_slot(): void
    {
        $calls = [];
        $this->fakeUssdApi($calls);
        $this->travelTo(now()->setTime(10, 1));

        $this->get('/cron/wrong-token')->assertNotFound();
        $this->getJson('/cron/'.BackgroundRunner::token())->assertOk()->assertJson(['status' => 'ran']);

        Settings::flush();
        $this->assertNotNull(Settings::get('scheduler_heartbeat'));
        $this->assertSame('pinger', Settings::get('runner_source'));
        $this->assertContains('/api/results', $calls);

        // Again within the same 3-minute slot: no second sync.
        $count = count($calls);
        $this->getJson('/cron/'.BackgroundRunner::token())->assertOk();
        $this->assertCount($count, $calls);

        $this->travel(3)->minutes();
        $this->getJson('/cron/'.BackgroundRunner::token())->assertOk();
        $this->assertGreaterThan($count, count($calls));
    }

    public function test_the_tick_command_sends_due_broadcasts(): void
    {
        $calls = [];
        $this->fakeUssdApi($calls);
        $broadcast = Broadcast::create(['title' => 'x', 'channel' => 'sms', 'message' => 'Hi', 'audience' => ['groups' => ['coordinators']], 'status' => 'scheduled', 'scheduled_at' => now()->subMinute(), 'created_by' => 'A']);

        $this->artisan('app:tick')->expectsOutput('Background work done.')->assertSuccessful();

        // No coordinators, so it finishes straight away.
        $this->assertSame('sent', $broadcast->fresh()->status);
        Settings::flush();
        $this->assertSame('cron', Settings::get('runner_source'));
    }

    public function test_the_system_page_shows_the_pinger_url(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/system')->assertOk()->assertSee(route('runner', BackgroundRunner::token()))->assertSee('Not running');

        $calls = [];
        $this->fakeUssdApi($calls);
        $this->get('/cron/'.BackgroundRunner::token());
        Settings::flush();
        $this->get('/system')->assertSee('✓ Running')->assertSee('(by the pinger)');
    }
}
