<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Staff accounts (email and password). Agents' accounts are made on their
 * first sign-in with their phone number and USSD PIN, and are managed on the
 * Agents page.
 */
class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', [
            'users' => User::query()->with('roleModel')->where('role', '!=', Role::AGENT)->orderBy('name')->get(),
            'agentAccounts' => User::query()->where('role', Role::AGENT)->count(),
            'roles' => $this->staffRoles(),
            'lgas' => PollingUnit::query()->whereNotNull('lga')->distinct()->orderBy('lga')->pluck('lga'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => strtolower((string) $request->input('email'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in($this->staffRoles()->pluck('key'))],
            'lga' => ['nullable', 'string', 'max:100', Rule::exists('polling_units', 'lga')],
            'password' => ['required', Password::min(10)],
        ]);

        $user = User::create($validated);
        Audit::record('user.created', "Added {$user->roleName()} {$user->name} ({$user->email})");

        return back()->with('status', "Added {$user->name}. Give them their password in person or by phone.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in($this->staffRoles()->pluck('key'))],
            'lga' => ['nullable', 'string', 'max:100', Rule::exists('polling_units', 'lga')],
            'password' => ['nullable', Password::min(10)],
        ]);

        if ($user->isAdmin() && $validated['role'] !== Role::ADMIN) {
            if ($user->is($request->user())) {
                return back()->with('error', 'You cannot remove your own admin role.');
            }

            if (User::query()->where('role', Role::ADMIN)->count() <= 1) {
                return back()->with('error', 'There must always be at least one admin.');
            }
        }

        $user->role = $validated['role'];
        $user->lga = $validated['lga'] ?? null;

        if (filled($validated['password'] ?? null)) {
            $user->password = $validated['password'];
            $user->remember_token = null;
        }

        $user->save();
        $user->unsetRelation('roleModel');
        Audit::record('user.updated', "Updated {$user->name}: {$user->roleName()}, ".($user->lga ?? 'state-wide').(filled($validated['password'] ?? null) ? ', new password' : ''));

        return back()->with('status', "Saved {$user->name}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->isAdmin() && User::query()->where('role', Role::ADMIN)->count() <= 1) {
            return back()->with('error', 'There must always be at least one admin.');
        }

        $user->delete();
        Audit::record('user.deleted', "Deleted {$user->name} (".($user->email ?? $user->phone).')');

        return back()->with('status', "Deleted {$user->name}.");
    }

    private function staffRoles()
    {
        return Role::query()->where('key', '!=', Role::AGENT)->orderByRaw("key = 'admin' desc")->orderBy('name')->get();
    }
}
