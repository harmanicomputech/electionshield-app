<?php

namespace App\Http\Controllers\Console;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

/**
 * Login. On a fresh install there are no accounts: the first admin is
 * created with ADMIN_PASSWORD from .env as a one-time setup key, which also
 * runs the migrations (the host has no terminal).
 */
class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        if (! $this->databaseReachable()) {
            return view('auth.login', ['mode' => 'no-database']);
        }

        if (! $this->hasUsers()) {
            abort_if(blank(config('election.admin_password')), 404);

            return view('auth.login', ['mode' => 'setup']);
        }

        return view('auth.login', ['mode' => 'login']);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $credentials['email'] = strtolower($credentials['email']);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            Audit::record('auth.failed', "Failed login for {$credentials['email']}", actor: 'Unknown');

            return back()->withInput($request->only('email'))->withErrors(['email' => 'Wrong email or password.']);
        }

        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();
        Audit::record('auth.login', 'Logged in');

        return redirect()->intended(route('dashboard'));
    }

    public function setup(Request $request): RedirectResponse
    {
        $key = (string) config('election.admin_password');
        abort_if($key === '', 404);

        $validated = $request->validate([
            'setup_key' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        if (! hash_equals($key, $validated['setup_key'])) {
            return back()->withInput($request->only('name', 'email'))
                ->withErrors(['setup_key' => 'Wrong setup key. It is ADMIN_PASSWORD in .env.']);
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Could not set up the database: '.$e->getMessage());
        }

        if ($this->hasUsers()) {
            return redirect()->route('login')->with('error', 'An account already exists. Log in instead.');
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => $validated['password'],
            'role' => UserRole::Admin,
        ]);

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        Audit::record('auth.setup', 'Created the first admin account');

        return redirect()->route('system')->with('status', "Welcome, {$user->name}. Connect the USSD service below, then add your team under Users.");
    }

    public function logout(Request $request): RedirectResponse
    {
        if (Auth::check()) {
            Audit::record('auth.logout', 'Logged out');
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('logged_out', true);
    }

    private function databaseReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasUsers(): bool
    {
        try {
            return User::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
