@extends('layouts.app')

@section('title', 'Users')

@section('content')
<div class="page-head">
    <h1>Users</h1>
    <p class="muted">Admins manage users and the USSD connection. Coordinators see the dashboards.</p>
</div>

<section class="card">
    <h2>Add a user</h2>
    <form method="post" action="{{ route('users.store') }}">
        @csrf
        <label for="name">Name</label>
        <input id="name" type="text" name="name" value="{{ old('name') }}" required>
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required>
        @error('email')<div class="field-error">{{ $message }}</div>@enderror
        <label for="role">Role</label>
        <select id="role" name="role">
            @foreach (\App\Enums\UserRole::cases() as $role)
                <option value="{{ $role->value }}" @selected(old('role', 'coordinator') === $role->value)>{{ $role->label() }}</option>
            @endforeach
        </select>
        <label for="password">Password (at least 10 characters)</label>
        <input id="password" type="text" name="password" required autocomplete="off">
        @error('password')<div class="field-error">{{ $message }}</div>@enderror
        <p></p>
        <button class="button" type="submit">Add user</button>
    </form>
</section>

<section class="card">
    <h2>{{ $users->count() }} users</h2>
    <div class="table-wrap">
        <table class="stack">
            <thead><tr><th>Name</th><th>Role</th><th>Last login</th><th>Change</th></tr></thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td class="key"><b>{{ $user->name }}</b><span class="muted small" style="display:block">{{ $user->email }}</span></td>
                        <td data-label="Role"><span class="badge">{{ $user->role->label() }}</span></td>
                        <td data-label="Last login">{{ $user->last_login_at ? \App\Support\Time::local($user->last_login_at, 'j M, g:i A') : 'never' }}</td>
                        <td data-label="Change">
                            <details>
                                <summary class="more-btn" style="display:inline-flex">Edit</summary>
                                <form method="post" action="{{ route('users.update', $user) }}">
                                    @csrf @method('put')
                                    <label for="role-{{ $user->id }}">Role</label>
                                    <select id="role-{{ $user->id }}" name="role">
                                        @foreach (\App\Enums\UserRole::cases() as $role)
                                            <option value="{{ $role->value }}" @selected($user->role === $role)>{{ $role->label() }}</option>
                                        @endforeach
                                    </select>
                                    <label for="pw-{{ $user->id }}">New password (optional)</label>
                                    <input id="pw-{{ $user->id }}" type="text" name="password" autocomplete="off">
                                    <p></p>
                                    <button class="button" type="submit">Save</button>
                                </form>
                                @unless ($user->is(auth()->user()))
                                    <form method="post" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Delete {{ $user->name }}?')" style="margin-top:8px">
                                        @csrf @method('delete')
                                        <button class="button danger" type="submit">Delete</button>
                                    </form>
                                @endunless
                            </details>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection
