<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_can_open_get_the_app_and_app_is_the_short_link(): void
    {
        $this->get('/app')->assertRedirect('/install');
        $this->get('/install')->assertOk()
            ->assertSee('Get the Election Shield app')
            ->assertSee('Install the app')
            ->assertSee('Add to Home Screen')
            ->assertSee('Open in Chrome')
            ->assertSee('intent://'.parse_url(url('/'), PHP_URL_HOST).(parse_url(url('/'), PHP_URL_PORT) ? ':'.parse_url(url('/'), PHP_URL_PORT) : '').'/install#Intent;scheme=http;package=com.android.chrome;end', false)
            ->assertSee('wa.me/?text=', false)
            ->assertSee(url('/app'));
    }

    public function test_get_the_app_is_in_the_menus_and_on_the_login_page(): void
    {
        $user = User::factory()->create();
        $this->get('/login')->assertSee('Get the app on your phone');

        $this->actingAs($user);
        $this->get('/')->assertOk()->assertSee('Get the app')->assertSee(route('install'));
        $this->get('/install')->assertOk()->assertSee('Share with your team');
    }
}
