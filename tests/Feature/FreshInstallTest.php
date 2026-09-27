<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FreshInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_login_cookie_from_an_earlier_install_does_not_break_set_up(): void
    {
        config(['election.admin_password' => 'setup-key']);
        User::factory()->admin()->create(['email' => 'old@example.com', 'password' => 'long-password']);

        $response = $this->post('/login', ['email' => 'old@example.com', 'password' => 'long-password', 'remember' => '1']);
        $recaller = Auth::guard('web')->getRecallerName();
        $cookie = $response->getCookie($recaller)->getValue();

        // Fresh install: same APP_KEY, empty database.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        Schema::disableForeignKeyConstraints();
        foreach (['push_subscriptions', 'alert_snoozes', 'attachments', 'users', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->withCookie($recaller, $cookie)->get('/login')->assertOk()->assertSee('Create the first admin');
        $this->withCookie($recaller, $cookie)->get('/')->assertRedirect('/login');
        $this->withCookie($recaller, $cookie)->get('/incidents')->assertRedirect('/login');
    }
}
