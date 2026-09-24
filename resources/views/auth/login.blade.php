@extends('layouts.app')

@section('title', $mode === 'setup' ? 'Set up' : 'Log in')

@section('content')
<div class="auth">
    @if ($mode === 'no-database')
        <div class="card">
            <h1>Database not reachable</h1>
            <p>Check the DB_ settings in <code>.env</code>, then reload this page.</p>
        </div>
    @elseif ($mode === 'setup')
        <div class="card">
            <h1>Create the first admin</h1>
            <p class="muted small">Enter the setup key (ADMIN_PASSWORD in .env). This also sets up the database.</p>
            <form method="post" action="{{ route('setup') }}">
                @csrf
                <label for="setup_key">Setup key</label>
                <input id="setup_key" type="password" name="setup_key" required autocomplete="off">
                @error('setup_key')<div class="field-error">{{ $message }}</div>@enderror
                <label for="name">Your name</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required autocomplete="name">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
                @error('email')<div class="field-error">{{ $message }}</div>@enderror
                <label for="password">Password (at least 10 characters)</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
                @error('password')<div class="field-error">{{ $message }}</div>@enderror
                <label for="password_confirmation">Password again</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                <p></p>
                <button class="button block" type="submit">Create admin account</button>
            </form>
        </div>
    @else
        <div class="card">
            <h1>Log in</h1>
            <p class="muted small">Election Shield situation room. Accounts are created by an admin.</p>
            <form method="post" action="{{ route('login.attempt') }}">
                @csrf
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                @error('email')<div class="field-error">{{ $message }}</div>@enderror
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
                <label class="inline"><input type="checkbox" name="remember" value="1" checked> Keep me logged in on this device</label>
                <button class="button block" type="submit">Log in</button>
            </form>
        </div>
    @endif
</div>
@if (session('logged_out'))<div data-clear-cache hidden></div>@endif
@endsection
