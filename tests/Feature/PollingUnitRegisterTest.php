<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\User;
use App\Services\CheckinVerdict;
use App\Services\PollingUnitImporter;
use App\Services\UssdIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PollingUnitRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bundled_register_has_every_ebonyi_polling_unit(): void
    {
        $result = app(PollingUnitImporter::class)->import(PollingUnitImporter::bundledPath());

        $this->assertSame(['created' => 2940, 'updated' => 0, 'errors' => []], $result);
        $this->assertSame(13, PollingUnit::query()->distinct()->count('lga'));
        $this->assertSame(171, PollingUnit::query()->select('lga', 'ward')->distinct()->get()->count());

        $unit = PollingUnit::where('code', '110101001')->sole();
        $this->assertSame(['ADAZI-ENU HALL I', 'Abakpa', 'Abakaliki', null], [$unit->name, $unit->ward, $unit->lga, $unit->registered_voters]);
        $this->assertSame('11/01/01/001', $unit->inecCode());
        $this->assertTrue($unit->hasApproximateLocation());
        $this->assertEqualsWithDelta(6.3166, $unit->latitude, 0.001);

        // Importing again updates in place and keeps voter numbers and check-in locations.
        $unit->update(['registered_voters' => 612]);
        PollingUnit::where('code', '110101002')->update(['latitude' => 6.3, 'longitude' => 8.1, 'location_source' => 'check-in by Ada, set by Admin']);
        $this->assertSame(['created' => 0, 'updated' => 2940, 'errors' => []], app(PollingUnitImporter::class)->import(PollingUnitImporter::bundledPath()));
        $this->assertSame(2940, PollingUnit::count());
        $this->assertSame(612, $unit->fresh()->registered_voters);
        $this->assertSame(6.3, PollingUnit::where('code', '110101002')->value('latitude'));
    }

    public function test_the_made_up_register_is_swapped_for_inecs_on_update(): void
    {
        PollingUnit::create(['code' => '21202633001', 'name' => 'Open Space 001', 'ward' => 'Abakaliki Ward 01', 'lga' => 'Abakaliki', 'registered_voters' => 1322]);
        $agent = Agent::create(['ussd_id' => 7, 'name' => 'Ada', 'phone_number' => '+2348011111111', 'polling_unit_code' => '21202633001']);

        $migration = require database_path('migrations/2026_10_01_000001_use_inec_polling_unit_register.php');
        $migration->up();

        $this->assertSame(2940, PollingUnit::count());
        $this->assertNull(PollingUnit::where('code', '21202633001')->first());
        $this->assertNull($agent->fresh()->polling_unit_code);
        $this->assertSame(1, AuditLog::where('action', 'system.pu_import')->count());

        // Only once: a database on INEC's codes is left alone.
        $migration->up();
        $this->assertSame(1, AuditLog::where('action', 'system.pu_import')->count());
    }

    public function test_replacing_needs_a_clean_file(): void
    {
        app(PollingUnitImporter::class)->import(PollingUnitImporter::bundledPath());

        $this->expectExceptionMessage('Not replacing the register');
        app(PollingUnitImporter::class)->import($this->csvFile('code,name,ward,lga
x,Bad,W,L
'), replace: true);
    }

    public function test_checkins_near_an_approximate_inec_location_count_as_near_the_pu(): void
    {
        $unit = PollingUnit::create(['code' => '110101001', 'name' => 'ADAZI-ENU HALL I', 'ward' => 'Abakpa', 'lga' => 'Abakaliki', 'latitude' => 6.3166, 'longitude' => 8.1160, 'location_source' => PollingUnitImporter::INEC_LOCATION]);
        $presence = new Presence(['latitude' => 6.3166 + 0.008, 'longitude' => 8.1160, 'location_accuracy' => 20, 'channel' => 'web']); // about 900 m north

        $verdict = app(CheckinVerdict::class)->for($presence, $unit);
        $this->assertSame('at_pu', $verdict['status']);
        $this->assertStringContainsString('approximate', $verdict['label']);

        $unit->location_source = 'check-in by Ada, set by Admin';
        $this->assertSame('away', app(CheckinVerdict::class)->for($presence, $unit)['status']);
    }

    public function test_every_real_lga_and_ward_page_opens(): void
    {
        app(PollingUnitImporter::class)->import(PollingUnitImporter::bundledPath());
        $this->actingAs(User::factory()->admin()->create());

        $wards = PollingUnit::query()->select('lga', 'ward')->distinct()->get();
        foreach ($wards->pluck('lga')->unique() as $lga) {
            $this->get(route('monitor.lga', $lga))->assertOk();
            $this->get(route('collation.lga', $lga))->assertOk();
        }
        foreach ($wards as $row) {
            // Some INEC ward names have a slash ("Amagu / Enyigba").
            $this->get(route('monitor.ward', [$row->lga, $row->ward]))->assertOk()->assertSee(e($row->ward), false);
            $this->get(route('collation.ward', [$row->lga, $row->ward]))->assertOk();
        }
        $this->get(route('official.collation', ['ward', 'Abakaliki', 'Amagu / Enyigba']))->assertOk();
    }

    private function csvFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pu');
        file_put_contents($path, $content);

        return $path;
    }

    public function test_setting_up_the_first_admin_loads_the_register(): void
    {
        $this->post('/setup', ['setup_key' => 'setup-key', 'name' => 'Kehinde', 'email' => 'k@example.com', 'password' => 'long-password', 'password_confirmation' => 'long-password'])
            ->assertRedirect('/system')
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'The register of 2940 polling units is loaded.'));

        $this->assertSame(2940, PollingUnit::count());
        $this->get('/monitor')->assertOk()->assertSee('Ohaukwu')->assertSee('0 / 304');
    }

    public function test_a_sync_from_the_ussd_service_updates_the_same_units(): void
    {
        app(PollingUnitImporter::class)->import(PollingUnitImporter::bundledPath());

        app(UssdIngestor::class)->pollingUnit(['code' => '110101001', 'name' => 'ADAZI-ENU HALL I (renamed)', 'ward' => 'Abakpa', 'lga' => 'Abakaliki', 'registered_voters' => 640]);

        $this->assertSame(2940, PollingUnit::count());
        $this->assertSame(640, PollingUnit::where('code', '110101001')->value('registered_voters'));
    }

    public function test_admins_import_from_the_system_page(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/system/polling-units')->assertSessionHas('status', 'Polling units from the bundled Ebonyi register: 2940 added, 0 updated.');

        $csv = "code,name,ward,lga,registered_voters\n11/01/01/001,New Name,Abakpa,Abakaliki,500\nEB/999/1,Bad,W,L,1\n110101999,Extra PU,Abakpa,Abakaliki,abc\n";
        $this->post('/system/polling-units', ['file' => UploadedFile::fake()->createWithContent('newer.csv', $csv)])
            ->assertSessionHas('error', fn ($message) => str_contains($message, '0 added, 1 updated') && str_contains($message, 'Line 3: invalid code') && str_contains($message, 'Line 4: invalid registered_voters'));

        $this->assertSame('New Name', PollingUnit::where('code', '110101001')->value('name'));
        $this->post('/system/polling-units', ['file' => UploadedFile::fake()->createWithContent('wrong.csv', "pu,name\n1,x\n")])->assertSessionHas('error', fn ($m) => str_contains($m, 'no "code" column'));
        $this->assertSame(2, AuditLog::where('action', 'system.pu_import')->count());
        $this->get('/system')->assertSee('2,940 polling units in 13 LGAs and 171 wards');

        $this->actingAs(User::factory()->create());
        $this->post('/system/polling-units')->assertForbidden();
    }

    public function test_the_command_and_the_seeder(): void
    {
        $this->artisan('pu:import')->expectsOutput('2940 polling units added, 0 updated.')->assertSuccessful();
        $this->seed();
        $this->assertSame(2940, PollingUnit::count());
    }
}
