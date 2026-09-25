<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Everyone's own account: name and password.
 */
class AccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('account.show', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $request->user()->forceFill(['name' => $validated['name']])->save();
        Audit::record('account.updated', 'Changed their name');

        return back()->with('status', 'Saved.');
    }

    public function password(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10), 'different:current_password'],
        ], ['current_password.current_password' => 'That is not your current password.']);

        $user = $request->user();
        $user->forceFill(['password' => $validated['password'], 'remember_token' => null])->save();
        $request->session()->regenerate();
        Audit::record('account.password', 'Changed their password');

        return back()->with('status', 'Password changed. Other devices will need the new password when their session ends.');
    }
}
