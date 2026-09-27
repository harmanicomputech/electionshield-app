<?php

namespace Tests\Feature;

use App\Models\IrevDocument;
use App\Models\OfficialResult;
use App\Models\PollingUnit;
use App\Models\User;
use App\Services\Irev\IrevWatcher;
use App\Services\IrevSheetReader;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IrevWatcherTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://dolphin-app-sleqh.ondigitalocean.app/api/v1';

    private const OID = '68fbd8c9f2b7c78fc9917c22';

    public static int $reads = 0;

    public static bool $legible = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Settings::flush();
        self::$reads = 0;
        self::$legible = true;
        config(['services.anthropic.key' => 'sk-test']);

        PollingUnit::query()->create(['code' => '21202633007', 'name' => 'Police Station 007', 'ward' => 'Abakaliki Ward 01', 'lga' => 'Abakaliki', 'registered_voters' => 1507]);
        PollingUnit::query()->create(['code' => '21202633008', 'name' => 'Market Square 008', 'ward' => 'Abakaliki Ward 01', 'lga' => 'Abakaliki', 'registered_voters' => 900]);

        $this->app->instance(IrevSheetReader::class, new class extends IrevSheetReader
        {
            protected function ask(array $content, array $schema): string
            {
                IrevWatcherTest::$reads++;

                return json_encode(IrevWatcherTest::$legible
                    ? ['legible' => true, 'pu_code' => '11/02/05/007', 'accredited_voters' => 700, 'rejected_votes' => 9, 'votes' => ['APC' => 300, 'PDP' => 250, 'LP' => 100, 'OTHERS' => 20], 'notes' => null]
                    : ['legible' => false, 'pu_code' => null, 'accredited_voters' => null, 'rejected_votes' => null, 'votes' => ['APC' => null, 'PDP' => null, 'LP' => null, 'OTHERS' => null], 'notes' => 'Blurred']);
            }
        });
    }

    private function entry(string $code, ?string $url, string $ward = 'ABAKALIKI WARD 01'): array
    {
        return [
            'pu_code' => $code, 'name' => 'PU '.$code, 'code' => substr($code, -3),
            'polling_unit' => ['pu_code' => $code, 'name' => 'PU '.$code, 'code' => substr($code, -3), 'lga' => ['name' => 'ABAKALIKI'], 'ward' => ['name' => $ward]],
            'document' => $url ? ['url' => $url, 'updated_at' => '2027-02-06T15:00:00.000Z'] : null,
        ];
    }

    private function fakeIrev(array $pus, int $imageStatus = 200): void
    {
        Http::fake([
            self::API.'/elections' => Http::response(['success' => true, 'data' => [
                ['_id' => self::OID, 'election_id' => 3001, 'full_name' => 'Governorship election - 2027-02-06 - EBONYI', 'election_date' => '2027-02-06T00:00:00.000Z'],
                ['_id' => '5f0eb67db39f166717b8411f', 'election_id' => 2919, 'full_name' => 'Governorship election - 2025-11-08 - ANAMBRA'],
            ]]),
            self::API.'/elections/'.self::OID.'/lga*' => Http::response(['success' => true, 'data' => [
                ['_id' => 'l1', 'lga' => ['name' => 'ABAKALIKI'], 'wards' => [['_id' => 'w1', 'name' => 'ABAKALIKI WARD 01'], ['_id' => 'w2', 'name' => 'ABAKALIKI WARD 02']]],
            ]]),
            self::API.'/elections/'.self::OID.'/pus*' => fn ($request) => Http::response(['success' => true, 'data' => str_contains($request->url(), 'ward=w1') ? $pus : []]),
            'inc-s3-cache.incportals.com/*' => Http::response($imageStatus === 200 ? "\xFF\xD8\xFFfake-jpeg" : 'Host not in allowlist', $imageStatus, ['Content-Type' => $imageStatus === 200 ? 'image/jpeg' : 'text/plain']),
        ]);
    }

    public function test_choosing_the_election_and_fetching_new_sheets_automatically(): void
    {
        $this->fakeIrev([
            $this->entry('11/02/05/007', 'https://inc-s3-cache.incportals.com/cached/express/results/1/007.jpg'),
            $this->entry('11/02/05/008', null),
            $this->entry('11/02/05/099', 'https://inc-s3-cache.incportals.com/cached/express/results/1/099.jpg'),
        ]);
        $this->actingAs(User::factory()->create());

        $this->get('/official/irev')->assertOk()->assertSee('Find the election on IReV');
        $this->post('/official/irev/elections')->assertSessionHas('irev_choices', fn ($choices) => count($choices) === 1 && $choices[0]['id'] === 3001);
        $this->post('/official/irev/follow', ['election' => self::OID.'|3001|Governorship election - 2027-02-06 - EBONYI'])->assertRedirect('/official/irev');
        $this->assertTrue(app(IrevWatcher::class)->automatic());

        $this->post('/official/irev/check')->assertSessionHas('status');

        // Matched by LGA, ward and PU number; saved, marked unchecked, sheet kept.
        $official = OfficialResult::query()->where('polling_unit_code', '21202633007')->sole();
        $this->assertTrue($official->needs_check);
        $this->assertSame('irev-auto', $official->source);
        $this->assertSame(['APC' => 300, 'PDP' => 250, 'LP' => 100, 'OTHERS' => 20], $official->votesByParty());
        Storage::disk('local')->assertExists($official->sheet_path);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'incportals.com') && $request->hasHeader('Referer', 'https://www.inecelectionresults.ng/'));

        $this->assertSame(IrevDocument::WAITING, IrevDocument::query()->where('irev_pu_code', '11/02/05/008')->value('status'));
        $this->assertSame(IrevDocument::UNMATCHED, IrevDocument::query()->where('irev_pu_code', '11/02/05/099')->value('status'));

        // The same sheet is not read twice.
        app(IrevWatcher::class)->step();
        $this->assertSame(1, self::$reads);

        $this->get('/official/pu/21202633007')->assertSee('not yet checked');
        $this->get('/compare')->assertSee('not yet checked by a person');
        $this->get('/official?unchecked=1')->assertSee('Police Station 007');

        // A person checks and saves it.
        $this->put('/official/pu/21202633007', ['irev_status' => 'uploaded', 'votes' => ['APC' => 300, 'PDP' => 250, 'LP' => 100, 'OTHERS' => 20], 'accredited_voters' => 700, 'rejected_votes' => 9]);
        $this->assertFalse($official->fresh()->needs_check);
        $this->assertSame('irev, checked', $official->fresh()->source);
    }

    public function test_unmatched_pus_are_matched_by_hand(): void
    {
        $this->fakeIrev([$this->entry('11/02/05/099', 'https://inc-s3-cache.incportals.com/cached/express/results/1/099.jpg')]);
        app(IrevWatcher::class)->follow(self::OID, 3001, 'Ebonyi 2027');
        app(IrevWatcher::class)->step();
        $document = IrevDocument::query()->sole();

        $this->actingAs(User::factory()->create());
        $this->get('/official/irev?status=unmatched')->assertSee('11/02/05/099');
        $this->post("/official/irev/{$document->id}/match", ['code' => '999'])->assertSessionHasErrors('code');
        $this->post("/official/irev/{$document->id}/match", ['code' => 'EB/212/02633/008'])->assertSessionHas('status');

        app(IrevWatcher::class)->step();
        $this->assertSame(IrevDocument::SAVED, $document->fresh()->status);
        $this->assertTrue(OfficialResult::query()->where('polling_unit_code', '21202633008')->exists());
    }

    public function test_a_persons_entry_is_never_overwritten(): void
    {
        OfficialResult::query()->create(['polling_unit_code' => '21202633007', 'irev_status' => 'uploaded', 'votes' => ['APC' => 1, 'PDP' => 2, 'LP' => 3, 'OTHERS' => 4], 'source' => 'manual', 'entered_by' => 'Coord']);
        $this->fakeIrev([$this->entry('11/02/05/007', 'https://inc-s3-cache.incportals.com/cached/express/results/1/007.jpg')]);
        app(IrevWatcher::class)->follow(self::OID, 3001, 'Ebonyi 2027');

        app(IrevWatcher::class)->step();

        $this->assertSame(IrevDocument::KEPT, IrevDocument::query()->sole()->status);
        $this->assertSame(1, OfficialResult::query()->sole()->votesByParty()['APC']);
        $this->assertSame(0, self::$reads);
    }

    public function test_unreadable_sheets_are_left_for_a_person(): void
    {
        self::$legible = false;
        $this->fakeIrev([$this->entry('11/02/05/007', 'https://inc-s3-cache.incportals.com/cached/express/results/1/007.jpg')]);
        app(IrevWatcher::class)->follow(self::OID, 3001, 'Ebonyi 2027');
        app(IrevWatcher::class)->step();
        $this->assertSame(IrevDocument::UNREADABLE, IrevDocument::query()->sole()->status);
        $this->assertSame(0, OfficialResult::query()->count());
    }

    public function test_a_refusing_image_store_is_reported(): void
    {
        $this->fakeIrev([$this->entry('11/02/05/007', 'https://inc-s3-cache.incportals.com/cached/express/results/1/007.jpg')], 403);
        app(IrevWatcher::class)->follow(self::OID, 3001, 'Ebonyi 2027');
        app(IrevWatcher::class)->step();
        $this->assertSame(IrevDocument::BLOCKED, IrevDocument::query()->sole()->status);
        $this->assertStringContainsString('403', (string) Settings::get('irev.last_error'));
    }

    public function test_the_background_runner_only_watches_when_switched_on(): void
    {
        $this->fakeIrev([$this->entry('11/02/05/007', 'https://inc-s3-cache.incportals.com/cached/express/results/1/007.jpg')]);
        config(['election.background_runner' => true]);
        app(IrevWatcher::class)->follow(self::OID, 3001, 'Ebonyi 2027');
        app(IrevWatcher::class)->setAutomatic(false);

        $this->artisan('app:tick')->assertSuccessful();
        $this->assertSame(0, IrevDocument::query()->count());

        app(IrevWatcher::class)->setAutomatic(true);
        $this->artisan('app:tick')->assertSuccessful();
        $this->assertSame(1, IrevDocument::query()->count());
    }

    public function test_observers_cannot_run_it(): void
    {
        $this->actingAs(User::factory()->role('observer')->create());
        $this->get('/official/irev')->assertForbidden();
        $this->post('/official/irev/check')->assertForbidden();
    }
}
