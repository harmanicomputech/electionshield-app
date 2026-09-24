<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UssdIngestor;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class ConsoleTest extends TestCase
{
    use RefreshDatabase;
    use SendsUssdEvents;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::flush();
    }

    public function test_the_first_admin_is_created_with_the_setup_key(): void
    {
        $this->get('/login')->assertOk()->assertSee('Create the first admin');

        $this->post('/setup', ['setup_key' => 'wrong', 'name' => 'Kehinde', 'email' => 'k@example.com', 'password' => 'long-password', 'password_confirmation' => 'long-password'])
            ->assertSessionHasErrors('setup_key');

        $this->post('/setup', ['setup_key' => 'setup-key', 'name' => 'Kehinde', 'email' => 'K@Example.com', 'password' => 'long-password', 'password_confirmation' => 'long-password'])
            ->assertRedirect('/system');

        $user = User::firstOrFail();
        $this->assertSame('k@example.com', $user->email);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertAuthenticatedAs($user);

        // Once an account exists, setup is closed.
        auth()->logout();
        $this->post('/setup', ['setup_key' => 'setup-key', 'name' => 'Other', 'email' => 'o@example.com', 'password' => 'long-password', 'password_confirmation' => 'long-password'])
            ->assertRedirect('/login');
        $this->assertSame(1, User::count());
    }

    public function test_login_and_logout(): void
    {
        $user = User::factory()->create(['email' => 'coord@example.com']);

        $this->post('/login', ['email' => 'coord@example.com', 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'Coord@example.com', 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'auth.failed')->exists());
    }

    public function test_guests_are_sent_to_login(): void
    {
        User::factory()->create();

        foreach (['/', '/spread', '/collation', '/system', '/users'] as $page) {
            $this->get($page)->assertRedirect('/login');
        }
    }

    public function test_coordinators_see_dashboards_but_not_admin_pages(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')->assertOk();
        $this->get('/system')->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->post('/system/sync')->assertForbidden();
    }

    public function test_dashboard_pages_show_the_collated_figures(): void
    {
        $this->actingAs(User::factory()->create());
        app(UssdIngestor::class)->result($this->resultPayload());

        $this->get('/')->assertOk()
            ->assertSee('PVT dashboard')
            ->assertSee('PUs reported (100%)')
            ->assertSee('APC')
            ->assertSee('54.22%');

        $this->get('/spread')->assertOk()->assertSee('Abakaliki');
        $this->get('/collation')->assertOk()->assertSee('Abakaliki')->assertSee('610');
        $this->get('/collation/Abakaliki')->assertOk()->assertSee('Abakaliki Ward 01');
        $this->get('/collation/Abakaliki/Abakaliki Ward 01')->assertOk()->assertSee('RS784321')->assertSee('EB/212/02633/007')->assertDontSee('+2348012345678');
        $this->get('/collation/Nowhere')->assertNotFound();
    }

    public function test_pages_render_with_no_data(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/')->assertOk()->assertSee('No results yet');
        $this->get('/spread')->assertOk();
        $this->get('/collation')->assertOk();
        $this->get('/system')->assertOk()->assertSee(url('/api/ussd-events'));
        $this->get('/users')->assertOk();
        $this->get('/audit')->assertOk();
    }

    public function test_the_data_view_switches_between_real_and_rehearsal(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        app(UssdIngestor::class)->result($this->resultPayload(['rehearsal' => true]), true);

        $this->get('/')->assertSee('No results yet')->assertDontSee('Rehearsal data:');

        $this->post('/system/data-view', ['view' => 'rehearsal'])->assertRedirect();
        $this->get('/')->assertSee('Rehearsal data:')->assertSee('54.22%');
    }

    public function test_admins_manage_users(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->post('/users', ['name' => 'Coord', 'email' => 'NEW@example.com', 'role' => 'coordinator', 'password' => 'long-password'])->assertRedirect();
        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->put("/users/{$user->id}", ['role' => 'admin', 'password' => ''])->assertRedirect();
        $this->assertTrue($user->fresh()->isAdmin());

        $this->put("/users/{$admin->id}", ['role' => 'coordinator'])->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->isAdmin());

        $this->delete("/users/{$admin->id}")->assertSessionHas('error');
        $this->delete("/users/{$user->id}")->assertRedirect();
        $this->assertNull($user->fresh());
    }

    public function test_sync_now_from_the_console(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Http::fake(fn () => Http::response(['data' => [], 'next_cursor' => null, 'server_time' => '2027-02-06T15:30:00+00:00', 'rehearsal_mode' => false]));

        $this->post('/system/sync', ['full' => 1])->assertRedirect()->assertSessionHas('status');
        $this->assertTrue(AuditLog::where('action', 'sync.run')->exists());
    }

    public function test_admins_can_update_the_database(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/system/migrate')->assertRedirect()->assertSessionHas('status');
        $this->assertTrue(AuditLog::where('action', 'system.migrate')->exists());
    }

    public function test_no_blade_directive_is_glued_to_a_word(): void
    {
        // Blade skips "@if" right after a letter or digit (it looks like an
        // email address), leaving an unmatched @endif that breaks the page.
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            preg_match_all('/[A-Za-z0-9]@(if|else|endif|foreach|endforeach|isset|endisset|unless|endunless)\b/', file_get_contents($file->getPathname()), $matches);
            $this->assertSame([], $matches[0], $file->getPathname());
        }
    }

    public function test_the_pwa_files_are_served(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame('Election Shield', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
            [$width] = getimagesize(public_path(ltrim($icon['src'], '/')));
            $this->assertSame((int) explode('x', $icon['sizes'])[0], $width);
        }

        $this->assertFileExists(public_path('sw.js'));
        $this->get('/offline')->assertOk()->assertSee('You are offline');
    }
}
