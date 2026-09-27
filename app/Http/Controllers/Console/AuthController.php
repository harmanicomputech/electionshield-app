<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\User;
use App\Services\PollingUnitImporter;
use App\Services\UssdApi;
use App\Services\UssdIngestor;
use App\Support\Audit;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        // Database and first admin first: before set-up there is no users
        // table, and a "keep me logged in" cookie from an earlier install
        // must not break this page.
        if (! $this->databaseReachable()) {
            return view('auth.login', ['mode' => 'no-database']);
        }

        if (! $this->hasUsers()) {
            abort_if(blank(config('election.admin_password')), 404);

            return view('auth.login', ['mode' => 'setup']);
        }

        if (Auth::check()) {
            return redirect()->route('dashboard');
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

    /**
     * Polling agents sign in with their phone number and the PIN they use on
     * USSD. The USSD service checks the PIN (wrong ones count towards its
     * lock-out); the agent's account here is made on their first sign-in.
     */
    public function agentLogin(Request $request, UssdApi $api, UssdIngestor $ingestor): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'pin' => ['required', 'string', 'max:10'],
        ]);
        $phone = Phone::normalize($validated['phone']);
        $back = fn (string $message) => back()->withInput($request->only('phone'))->withErrors(['phone' => $message]);

        if ($phone === null) {
            return $back('Enter your phone number, for example 0803 123 4567.');
        }

        if (! $api->enabled()) {
            return $back('Agent sign-in is not connected yet. Ask an admin to set USSD_API_TOKEN.');
        }

        try {
            $response = $api->post('agents/verify-pin', ['phone_number' => $phone, 'pin' => $validated['pin']]);
        } catch (Throwable $e) {
            report($e);

            return $back('Could not reach the USSD service. Check your connection and try again.');
        }

        if (! $response->successful()) {
            Audit::record('auth.failed', "Failed agent sign-in for {$phone}", actor: 'Unknown');

            return $back((string) ($response->json('message') ?: 'Could not sign you in ('.$response->status().').'));
        }

        $agent = $ingestor->agent($response->json('agent'));
        $user = User::query()->where('phone', $agent->phone_number)->first();

        if ($user && ! $user->isAgent()) {
            return $back('This phone number belongs to a staff account. Log in with your email and password.');
        }

        $user ??= new User(['role' => Role::AGENT, 'phone' => $agent->phone_number, 'password' => Str::random(40)]);
        $user->name = $agent->name ?: $agent->phone_number;
        $user->save();

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::record('auth.login', 'Agent signed in');

        return redirect()->route('field');
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
            'role' => Role::ADMIN,
        ]);

        // The PU register ships with the app, so the boards work before
        // the USSD service is connected.
        $imported = '';
        try {
            if (! PollingUnit::query()->exists()) {
                $result = app(PollingUnitImporter::class)->import(PollingUnitImporter::bundledPath());
                $imported = " The register of {$result['created']} polling units is loaded.";
            }
        } catch (Throwable $e) {
            report($e);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        Audit::record('auth.setup', 'Created the first admin account');

        return redirect()->route('system')->with('status', "Welcome, {$user->name}.{$imported} Connect the USSD service below, then add your team under Users.");
    }

    public function logout(Request $request): RedirectResponse
    {
        if (Auth::check()) {
            // The device's notifications stop with the session (shared phones).
            if (filled($request->input('push_endpoint'))) {
                $request->user()->pushSubscriptions()->where('endpoint_hash', PushSubscription::hashEndpoint((string) $request->input('push_endpoint')))->delete();
            }

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
