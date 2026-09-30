@extends('layouts.app')

@section('title', 'Volunteers')

@php
    $keep = array_filter(['lga' => $filters['lga'], 'ward' => $filters['ward'], 'status' => $filters['status'] !== 'all' ? $filters['status'] : null, 'q' => $filters['q'] !== '' ? $filters['q'] : null]);
    $initials = fn (string $name) => collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
@endphp

@section('content')
<div class="page-head">
    <h1>Volunteers</h1>
    <p class="muted">People who signed up on USSD with “How can you help?”. Anyone can dial the code. Each contact number is one volunteer (signing up again with the same number updates it); one phone can sign up several people.</p>
</div>

<div class="stats vol-stats">
    <a class="stat" href="{{ route('volunteers') }}"><b>{{ number_format($total) }}</b><span>Volunteers</span></a>
    <a class="stat {{ $notContacted ? 'tone-warn' : '' }}" href="{{ route('volunteers', [...$keep, 'status' => 'new']) }}"><b>{{ number_format($notContacted) }}</b><span>Not contacted yet</span></a>
    <div class="stat"><b>{{ number_format($newToday) }}</b><span>Signed up today</span></div>
</div>

<nav class="role-chips" aria-label="How they can help">
    <a href="{{ route('volunteers', $keep) }}" class="{{ $filters['role'] ? '' : 'on' }}">Everyone <b>{{ number_format($areaCount) }}</b></a>
    @foreach (\App\Models\Volunteer::ROLES as $key => $label)
        <a href="{{ route('volunteers', [...$keep, 'role' => $key]) }}" class="{{ $filters['role'] === $key ? 'on' : '' }}">{{ $label }} <b>{{ number_format($roleCounts[$key]) }}</b></a>
    @endforeach
</nav>

<form class="filters" method="get" action="{{ route('volunteers') }}">
    @if ($filters['role'])<input type="hidden" name="role" value="{{ $filters['role'] }}">@endif
    <div>
        <label for="lga">LGA</label>
        <select id="lga" name="lga" data-autosubmit>
            <option value="">All LGAs</option>
            @foreach ($lgas as $option)<option value="{{ $option }}" @selected($filters['lga'] === $option)>{{ $option }}</option>@endforeach
        </select>
    </div>
    <div>
        <label for="ward">Ward</label>
        <select id="ward" name="ward" data-autosubmit @disabled($wards->isEmpty())>
            <option value="">{{ $filters['lga'] ? 'All wards' : 'Choose an LGA first' }}</option>
            @foreach ($wards as $option)<option value="{{ $option }}" @selected($filters['ward'] === $option)>{{ $filters['lga'] && str_starts_with($option, $filters['lga'].' ') ? substr($option, strlen($filters['lga']) + 1) : $option }}</option>@endforeach
        </select>
    </div>
    <div>
        <label for="status">Follow-up</label>
        <select id="status" name="status" data-autosubmit>
            <option value="all" @selected($filters['status'] === 'all')>Everyone</option>
            <option value="new" @selected($filters['status'] === 'new')>Not contacted yet</option>
            <option value="contacted" @selected($filters['status'] === 'contacted')>Contacted</option>
        </select>
    </div>
    <div>
        <label for="q">Find</label>
        <input id="q" type="search" name="q" value="{{ $filters['q'] }}" placeholder="Name, number or reference">
    </div>
    <div class="wide"><button class="button secondary" type="submit">Search</button></div>
</form>

<div class="vol-toolbar">
    <h2>{{ number_format($matching) }} {{ $matching === 1 ? 'volunteer' : 'volunteers' }}@if ($filters['role']) · {{ \App\Models\Volunteer::ROLES[$filters['role']] }}@endif</h2>
    <div class="row-actions">
        @if ($canBroadcast)<a class="button secondary" href="{{ route('broadcasts.create', array_filter(['groups' => ['volunteers'], 'lgas' => $filters['lga'] ? [$filters['lga']] : null])) }}">Text them (SMS broadcast)</a>@endif
        @if ($canExport)<a class="button secondary" href="{{ route('volunteers.export', request()->query()) }}" data-no-progress>Download CSV</a>@endif
    </div>
</div>

@if ($volunteers->isEmpty())
    <div class="card"><p class="muted">No volunteers here yet. They appear as soon as someone signs up on USSD (“How can you help?”).</p></div>
@endif

<ul class="list two vol-list">
    @foreach ($volunteers as $volunteer)
        <li class="item vol-card {{ $volunteer->contacted_at ? 'is-contacted' : '' }}" id="volunteer-{{ $volunteer->reference }}">
            <div class="vol-head">
                <span class="avatar" aria-hidden="true">{{ $initials($volunteer->name) }}</span>
                <div class="who">
                    <h3>{{ $volunteer->name }}</h3>
                    <div class="meta">{{ $volunteer->wardLabel() }}, {{ $volunteer->lga }} · {{ $volunteer->reference }}</div>
                </div>
                @if ($volunteer->contacted_at)
                    <span class="badge good">✓ Contacted</span>
                @else
                    <span class="badge warn">New</span>
                @endif
            </div>
            <div class="badges">
                @foreach ($volunteer->roleLabels() as $label)<span class="badge">{{ $label }}</span>@endforeach
                @if ($volunteer->is_agent)<span class="badge channel-web">Polling agent</span>@endif
            </div>
            @if ($volunteer->skills)<div class="meta">Skills: {{ implode(', ', $volunteer->skillLabels()) }}</div>@endif
            @if ($volunteer->other)<p class="note">“{{ $volunteer->other }}”</p>@endif
            <div class="meta">Signed up {{ $volunteer->registered_at ? \App\Support\Time::local($volunteer->registered_at, 'j M, g:i A') : '' }}@if ($volunteer->contact_phone !== $volunteer->phone_number) · dialled from {{ $volunteer->phone_number }}@endif</div>
            @if ($volunteer->contacted_at)
                <p class="response">Contacted by {{ $volunteer->contacted_by }} on {{ \App\Support\Time::local($volunteer->contacted_at, 'j M, g:i A') }}@if ($volunteer->follow_up_note): “{{ $volunteer->follow_up_note }}”@endif</p>
            @endif
            <div class="actions row-actions">
                <a class="button" href="tel:{{ $volunteer->contact_phone }}">Call {{ $volunteer->contact_phone }}</a>
                <a class="button secondary" href="https://wa.me/{{ $volunteer->whatsappNumber() }}?text={{ rawurlencode('Hello '.strtok($volunteer->name, ' ').', thank you for signing up to help with Election Shield.') }}" target="_blank" rel="noopener">WhatsApp</a>
                @if ($volunteer->contacted_at)
                    <form method="post" action="{{ route('volunteers.contacted', $volunteer) }}" data-queue="Undo contacted {{ $volunteer->reference }}">
                        @csrf
                        <input type="hidden" name="contacted" value="0">
                        <button class="button secondary" type="submit">Undo</button>
                    </form>
                @else
                    <details class="vol-contacted">
                        <summary class="button secondary">Mark contacted…</summary>
                        <form method="post" action="{{ route('volunteers.contacted', $volunteer) }}" data-queue="Contacted {{ $volunteer->reference }}">
                            @csrf
                            <input type="hidden" name="contacted" value="1">
                            <label for="note-{{ $volunteer->reference }}">Note (optional)</label>
                            <input id="note-{{ $volunteer->reference }}" name="follow_up_note" maxlength="500" placeholder="e.g. Will canvass Ward 03 on Saturday">
                            <button class="button" type="submit">Save</button>
                        </form>
                    </details>
                @endif
            </div>
            <div class="queue-state" data-queue-state aria-live="polite"></div>
        </li>
    @endforeach
</ul>

<div class="pagination">
    @if ($volunteers->previousPageUrl())<a class="button secondary" href="{{ $volunteers->previousPageUrl() }}">← Newer</a>@endif
    @if ($volunteers->nextPageUrl())<a class="button secondary" href="{{ $volunteers->nextPageUrl() }}">Older →</a>@endif
</div>
@endsection
