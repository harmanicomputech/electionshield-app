@extends('layouts.app')

@section('title', $role->exists ? 'Edit role' : 'New role')

@section('content')
<div class="page-head">
    <p class="small"><a href="{{ route('roles') }}">← Roles</a></p>
    <h1>{{ $role->exists ? $role->name : 'New role' }}</h1>
    @if ($role->isAdmin())
        <p class="muted">The admin role always has every permission, so there is nothing to tick here.</p>
    @elseif ($role->key === \App\Models\Role::AGENT)
        <p class="muted">Agents sign in with their phone number and USSD PIN. Keep “Use the agent pages”; anything else you tick is added on top.</p>
    @else
        <p class="muted">Tick what people with this role may do.</p>
    @endif
</div>

<form method="post" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}" class="card">
    @csrf
    @if ($role->exists) @method('put') @endif
    <label for="name">Name</label>
    <input id="name" type="text" name="name" value="{{ old('name', $role->name) }}" maxlength="80" required @disabled($role->isAdmin())>
    @error('name')<div class="field-error">{{ $message }}</div>@enderror
    @if ($role->isAdmin())<input type="hidden" name="name" value="{{ $role->name }}">@endif
    <label for="description">Description (optional)</label>
    <input id="description" type="text" name="description" value="{{ old('description', $role->description) }}" maxlength="300">

    @unless ($role->isAdmin())
        @php
            $granted = old('permissions', $role->permissions ?? []);
        @endphp
        @foreach ($groups as $group => $permissions)
            <fieldset class="perm-group">
                <legend>{{ $group }}</legend>
                @foreach ($permissions as $key => [$label, $hint])
                    <label class="perm">
                        <input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, $granted, true))>
                        <span><b>{{ $label }}</b>@if ($hint)<span class="muted small">{{ $hint }}</span>@endif</span>
                    </label>
                @endforeach
            </fieldset>
        @endforeach
    @endunless

    <p></p>
    <button class="button" type="submit">{{ $role->exists ? 'Save role' : 'Create role' }}</button>
</form>
@endsection
