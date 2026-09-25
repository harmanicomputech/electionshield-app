<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PollingUnit;
use App\Models\User;
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

        $this->assertSame(['created' => 3308, 'updated' => 0, 'errors' => []], $result);
        $this->assertSame(13, PollingUnit::query()->distinct()->count('lga'));
        $this->assertSame(169, PollingUnit::query()->select('lga', 'ward')->distinct()->get()->count());

        $unit = PollingUnit::where('code', '21202633001')->sole();
        $this->assertSame(['Open Space 001', 'Abakaliki Ward 01', 'Abakaliki', 1322], [$unit->name, $unit->ward, $unit->lga, $unit->registered_voters]);
        $this->assertSame('EB/212/02633/001', $unit->inecCode());

        // Importing again updates in place.
        $this->assertSame(['created' => 0, 'updated' => 3308, 'errors' => []], app(PollingUnitImporter::class)->import(PollingUnitImporter::bundledPath()));
        $this->assertSame(3308, PollingUnit::count());
    }

    public function test_setting_up_the_first_admin_loads_the_register(): void
    {
        $this->post('/setup', ['setup_key' => 'setup-key', 'name' => 'Kehinde', 'email' => 'k@example.com', 'password' => 'long-password', 'password_confirmation' => 'long-password'])
            ->assertRedirect('/system')
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'The register of 3308 polling units is loaded.'));

        $this->assertSame(3308, PollingUnit::count());
        $this->get('/monitor')->assertOk()->assertSee('Ohaukwu')->assertSee('0 / 186');
    }

    public function test_a_sync_from_the_ussd_service_updates_the_same_units(): void
    {
        app(PollingUnitImporter::class)->import(PollingUnitImporter::bundledPath());

        app(UssdIngestor::class)->pollingUnit(['code' => 'EB/212/02633/001', 'name' => 'Open Space 001 (renamed)', 'ward' => 'Abakaliki Ward 01', 'lga' => 'Abakaliki', 'registered_voters' => 1400]);

        $this->assertSame(3308, PollingUnit::count());
        $this->assertSame(1400, PollingUnit::where('code', '21202633001')->value('registered_voters'));
    }

    public function test_admins_import_from_the_system_page(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/system/polling-units')->assertSessionHas('status', 'Polling units from the bundled Ebonyi register: 3308 added, 0 updated.');

        $csv = "code,name,ward,lga,registered_voters\nEB/212/02633/001,New Name,Abakaliki Ward 01,Abakaliki,1500\nEB/999/1,Bad,W,L,1\n21202633999,Extra PU,Abakaliki Ward 01,Abakaliki,abc\n";
        $this->post('/system/polling-units', ['file' => UploadedFile::fake()->createWithContent('newer.csv', $csv)])
            ->assertSessionHas('error', fn ($message) => str_contains($message, '0 added, 1 updated') && str_contains($message, 'Line 3: invalid code') && str_contains($message, 'Line 4: invalid registered_voters'));

        $this->assertSame('New Name', PollingUnit::where('code', '21202633001')->value('name'));
        $this->post('/system/polling-units', ['file' => UploadedFile::fake()->createWithContent('wrong.csv', "pu,name\n1,x\n")])->assertSessionHas('error', fn ($m) => str_contains($m, 'no "code" column'));
        $this->assertSame(2, AuditLog::where('action', 'system.pu_import')->count());
        $this->get('/system')->assertSee('3,308 polling units in 13 LGAs and 169 wards');

        $this->actingAs(User::factory()->create());
        $this->post('/system/polling-units')->assertForbidden();
    }

    public function test_the_command_and_the_seeder(): void
    {
        $this->artisan('pu:import')->expectsOutput('3308 polling units added, 0 updated.')->assertSuccessful();
        $this->seed();
        $this->assertSame(3308, PollingUnit::count());
    }
}
