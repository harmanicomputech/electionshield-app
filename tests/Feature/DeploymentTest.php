<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeploymentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(base_path('DEPLOY_ID'));
        @unlink(storage_path('framework/deploy-id'));
        parent::tearDown();
    }

    public function test_a_new_upload_clears_compiled_pages_once(): void
    {
        $compiled = config('view.compiled').'/stale-test-view.php';
        file_put_contents($compiled, 'old page');

        Deployment::refreshIfChanged(); // no DEPLOY_ID: nothing happens
        $this->assertFileExists($compiled);

        file_put_contents(base_path('DEPLOY_ID'), "20260929-abc\n");
        Deployment::refreshIfChanged();
        $this->assertFileDoesNotExist($compiled);
        $this->assertSame('20260929-abc', trim(file_get_contents(storage_path('framework/deploy-id'))));

        // Same release again: kept.
        file_put_contents($compiled, 'new page');
        Deployment::refreshIfChanged();
        $this->assertFileExists($compiled);
        @unlink($compiled);
    }

    public function test_a_new_upload_also_runs_pending_database_updates(): void
    {
        // A migration that came with the upload and hasn't run yet.
        $dir = storage_path('framework/testing/deploy-migrations');
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/2099_01_01_000000_create_deploy_probe.php', '<?php use Illuminate\\Database\\Migrations\\Migration; use Illuminate\\Database\\Schema\\Blueprint; use Illuminate\\Support\\Facades\\Schema; return new class extends Migration { public function up(): void { Schema::create("deploy_probe", fn (Blueprint $t) => $t->id()); } public function down(): void { Schema::dropIfExists("deploy_probe"); } };');
        $this->app->make('migrator')->path($dir);
        $this->assertFalse(Schema::hasTable('deploy_probe'));

        file_put_contents(base_path('DEPLOY_ID'), "20260930-xyz\n");
        Deployment::refreshIfChanged();

        $this->assertTrue(Schema::hasTable('deploy_probe'));
        $this->assertTrue(AuditLog::query()->where('action', 'system.migrate')->exists());
        @unlink($dir.'/2099_01_01_000000_create_deploy_probe.php');
    }

    public function test_updating_the_database_also_refreshes_the_page_cache(): void
    {
        $compiled = config('view.compiled').'/stale-test-view.php';
        file_put_contents($compiled, 'old page');

        $this->actingAs(User::factory()->admin()->create())->post('/system/migrate')
            ->assertSessionHas('status', 'The database is up to date, and the page cache was refreshed.');
        $this->assertFileDoesNotExist($compiled);
    }
}
