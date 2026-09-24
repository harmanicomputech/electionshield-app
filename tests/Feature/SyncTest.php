<?php

namespace Tests\Feature;

use App\Enums\ResultStatus;
use App\Models\Agent;
use App\Models\PollingUnit;
use App\Models\Result;
use App\Models\SyncState;
use App\Services\UssdSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;
    use SendsUssdEvents;

    /**
     * @param  list<array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    private function page(array $data, ?string $cursor = null, bool $rehearsal = false, string $serverTime = '2027-02-06T15:30:00+00:00'): array
    {
        return ['data' => $data, 'next_cursor' => $cursor, 'next_page_url' => null, 'server_time' => $serverTime, 'rehearsal_mode' => $rehearsal];
    }

    private function empty(): array
    {
        return $this->page([]);
    }

    public function test_a_full_import_follows_every_cursor(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $path = parse_url($request->url(), PHP_URL_PATH);

            $this->assertSame('Bearer api-token', $request->header('Authorization')[0]);

            return Http::response(match (true) {
                $path === '/api/polling-units' && ! isset($query['cursor']) => $this->page([$this->unit('21202633007')], 'c2'),
                $path === '/api/polling-units' => $this->page([$this->unit('21202633008')]),
                $path === '/api/agents' => $this->page([['id' => 5, 'name' => 'Ada Obi', 'phone_number' => '+2348012345678', 'polling_unit' => $this->unit(), 'locked' => false, 'last_seen_at' => null]]),
                $path === '/api/results' => $this->page([$this->resultPayload(['reference' => 'RS1', 'status' => 'superseded']), $this->resultPayload(['reference' => 'RS2', 'corrects_reference' => 'RS1'])]),
                default => $this->empty(),
            });
        });

        $report = app(UssdSync::class)->run();

        $this->assertSame(2, $report['polling-units']['count']);
        $this->assertSame(2, PollingUnit::count());
        $this->assertSame('21202633007', Agent::firstOrFail()->polling_unit_code);
        $this->assertSame(ResultStatus::Superseded, Result::where('reference', 'RS1')->value('status'));
        $this->assertSame(['RS2'], Result::counted(false)->pluck('reference')->all());
        $this->assertSame('2027-02-06 15:30:00', SyncState::find('results')->synced_until->format('Y-m-d H:i:s'));
    }

    public function test_later_runs_ask_only_for_changes_with_a_minute_of_overlap(): void
    {
        SyncState::create(['resource' => 'results', 'synced_until' => '2027-02-06 15:30:00']);
        $asked = [];

        Http::fake(function (Request $request) use (&$asked) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $asked[parse_url($request->url(), PHP_URL_PATH)] = $query['updated_since'] ?? null;

            return Http::response($this->empty());
        });

        app(UssdSync::class)->run();

        $this->assertSame('2027-02-06T15:29:00+00:00', $asked['/api/results']);
        $this->assertNull($asked['/api/incidents']);
    }

    public function test_pulled_records_take_rehearsal_mode_but_keep_a_known_flag(): void
    {
        $this->sendEvent('result.submitted', $this->resultPayload(['reference' => 'RS1', 'rehearsal' => false]))->assertOk();

        $pulled = [$this->resultPayload(['reference' => 'RS1']), $this->resultPayload(['reference' => 'RS9', 'polling_unit' => $this->unit('21202633008')])];
        unset($pulled[0]['rehearsal'], $pulled[1]['rehearsal']);

        Http::fake(fn (Request $request) => Http::response(str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/results') ? $this->page($pulled, rehearsal: true) : $this->empty()));

        app(UssdSync::class)->run();

        $this->assertFalse(Result::where('reference', 'RS1')->value('rehearsal'));
        $this->assertTrue(Result::where('reference', 'RS9')->value('rehearsal'));
    }

    public function test_a_failing_resource_is_recorded_and_the_rest_still_sync(): void
    {
        Http::fake(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/agents')
            ? Http::response(['message' => 'Server error'], 500)
            : Http::response($this->empty()));

        $report = app(UssdSync::class)->run();

        $this->assertNotNull($report['agents']['error']);
        $this->assertNotNull(SyncState::find('agents')->last_error);
        $this->assertNull($report['results']['error']);
        $this->assertNotNull(SyncState::find('results')->last_success_at);
    }

    public function test_the_command_does_nothing_without_a_token(): void
    {
        config(['services.ussd.api_token' => null]);
        Http::fake();

        $this->artisan('ussd:sync')->assertSuccessful();
        Http::assertNothingSent();
    }
}
