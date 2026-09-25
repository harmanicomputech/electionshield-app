@extends('layouts.app')

@section('title', 'My account')

@section('content')
<div class="page-head">
    <h1>My account</h1>
    <p class="muted">{{ $user->email }} · {{ $user->role->label() }} · {{ $user->lga ? 'home LGA '.$user->lga : 'state-wide' }}{{ $user->isAdmin() ? '' : ' (set by an admin)' }}</p>
</div>

<div class="grid two">
    <section class="card">
        <h2>Name</h2>
        <form method="post" action="{{ route('account.update') }}">
            @csrf @method('put')
            <label for="acc-name">Name shown to your team</label>
            <input id="acc-name" type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="255" autocomplete="name">
            @error('name')<div class="field-error">{{ $message }}</div>@enderror
            <p></p>
            <button class="button" type="submit">Save</button>
        </form>
    </section>

    <section class="card">
        <h2>Change password</h2>
        <form method="post" action="{{ route('account.password') }}">
            @csrf @method('put')
            <label for="acc-current">Current password</label>
            <input id="acc-current" type="password" name="current_password" required autocomplete="current-password">
            @error('current_password')<div class="field-error">{{ $message }}</div>@enderror
            <label for="acc-new">New password (at least 10 characters)</label>
            <input id="acc-new" type="password" name="password" required autocomplete="new-password">
            @error('password')<div class="field-error">{{ $message }}</div>@enderror
            <label for="acc-confirm">New password again</label>
            <input id="acc-confirm" type="password" name="password_confirmation" required autocomplete="new-password">
            <p></p>
            <button class="button" type="submit">Change password</button>
        </form>
    </section>
</div>
@endsection
