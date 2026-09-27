@extends('layouts.app')

@section('title', 'Roles')

@section('content')
<div class="page-head">
    <h1>Roles and permissions</h1>
    <p class="muted">Each person has one role; the role decides what they can see and do. Changes apply straight away. Give people roles on the <a href="{{ route('users') }}">Users page</a>.</p>
</div>

<p><a class="button" href="{{ route('roles.create') }}">New role</a></p>

<div class="grid two">
    @foreach ($roles as $role)
        <section class="card role-card">
            <div class="role-head">
                <h2>{{ $role->name }}</h2>
                <span class="badge">{{ $role->users_count }} {{ $role->users_count === 1 ? 'person' : 'people' }}</span>
            </div>
            @if ($role->description)<p class="muted small">{{ $role->description }}</p>@endif
            @if ($role->isAdmin())
                <p class="small"><b>Everything.</b> The admin role always has every permission.</p>
            @else
                <ul class="perm-list small">
                    @forelse ($role->grants() as $permission)
                        <li>✓ {{ \App\Support\Permission::label($permission) }}</li>
                    @empty
                        <li class="muted">No permissions yet.</li>
                    @endforelse
                </ul>
            @endif
            <div class="actions">
                <a class="button secondary" href="{{ route('roles.edit', $role) }}">Edit</a>
                @unless ($role->system)
                    <form method="post" action="{{ route('roles.destroy', $role) }}" onsubmit="return confirm('Delete the {{ $role->name }} role?')">
                        @csrf @method('delete')
                        <button class="button danger" type="submit">Delete</button>
                    </form>
                @endunless
            </div>
        </section>
    @endforeach
</div>
@endsection
