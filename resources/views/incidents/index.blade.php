@extends('layouts.app')

@section('title', 'Incidents')

@php
    $tabs = [
        'unresolved' => ['Unresolved', $counts['open'] + $counts['acknowledged']],
        'open' => ['Open', $counts['open']],
        'acknowledged' => ['Acknowledged', $counts['acknowledged']],
        'resolved' => ['Resolved', $counts['resolved']],
    ];
    $statusBadge = ['open' => ['bad', 'Open'], 'acknowledged' => ['warn', 'Acknowledged'], 'resolved' => ['good', '✓ Resolved']];
    $query = request()->except('page', 'status');
@endphp

@section('content')
<div class="page-head">
    <h1>Incidents</h1>
    <p class="muted">Reported by agents on USSD. Urgent ones also text the coordinators. @if ($urgentOpen)<b>{{ $urgentOpen }} urgent {{ $urgentOpen === 1 ? 'incident needs' : 'incidents need' }} acknowledging.</b>@endif</p>
</div>

<nav class="tabs" aria-label="Status">
    @foreach ($tabs as $key => [$label, $count])
        <a href="{{ route('incidents', [...$query, 'status' => $key]) }}" class="{{ $status === $key ? 'on' : '' }}">{{ $label }} <b>{{ $count }}</b></a>
    @endforeach
</nav>

<form class="filters" method="get" action="{{ route('incidents') }}">
    <input type="hidden" name="status" value="{{ $status }}">
    <div>
        <label for="type">Type</label>
        <select id="type" name="type" data-autosubmit>
            <option value="">All types</option>
            @foreach ($types as $type)
                <option value="{{ $type->type }}" @selected(request('type') === $type->type)>{{ $type->type_label ?: $type->type }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="lga">LGA</label>
        <select id="lga" name="lga" data-autosubmit>
            <option value="">All LGAs</option>
            @foreach ($lgas as $lga)
                <option value="{{ $lga }}" @selected(request('lga') === $lga)>{{ $lga }}</option>
            @endforeach
        </select>
    </div>
    <div class="wide">
        <label class="inline"><input type="checkbox" name="urgent" value="1" data-autosubmit @checked(request()->boolean('urgent'))> Urgent only</label>
    </div>
    <div class="wide"><button class="button secondary" type="submit" data-js-hide>Filter</button></div>
</form>

@if ($incidents->isEmpty())
    <div class="card"><p class="muted">No incidents here.</p></div>
@endif

<ul class="list two">
    @foreach ($incidents as $incident)
        @php([$badgeClass, $badgeText] = $statusBadge[$incident->responseStatus()])
        <li class="item {{ $incident->urgent && ! $incident->resolved_at ? 'urgent' : '' }}" id="incident-{{ $incident->reference }}">
            <div class="badges" style="margin-top:0">
                @if ($incident->urgent)<span class="badge bad">⚠ Urgent</span>@endif
                <span class="badge">{{ $incident->label() }}</span>
                <span class="badge {{ $badgeClass }}">{{ $badgeText }}</span>
            </div>
            <h3>{{ $incident->pollingUnit?->name ?? 'PU '.$incident->polling_unit_code }}</h3>
            <div class="meta">
                @if ($incident->lga && $incident->ward)
                    <a href="{{ route('monitor.ward', [$incident->lga, $incident->ward]) }}">{{ $incident->lga }} › {{ $incident->ward }}</a> ·
                @endif
                {{ $incident->reference }} · {{ \App\Support\Time::local($incident->reported_at, 'j M, g:i A') }}
            </div>
            @if ($incident->note)<p class="note">{{ $incident->note }}</p>@endif
            <div class="meta">Reported by {{ $incident->agent_name ?? 'an agent' }}@if ($incident->agent_phone) · <a class="tel" href="tel:{{ $incident->agent_phone }}">Call {{ $incident->agent_phone }}</a>@endif</div>

            @if ($incident->acknowledged_at)
                <p class="response">Acknowledged by {{ $incident->acknowledged_by }} at {{ \App\Support\Time::local($incident->acknowledged_at) }}.
                    @if ($incident->resolved_at) Resolved by {{ $incident->resolved_by }} at {{ \App\Support\Time::local($incident->resolved_at) }}@if ($incident->resolution_note): “{{ $incident->resolution_note }}”@endif.@endif
                </p>
            @endif

            <div class="actions">
                @if (! $incident->acknowledged_at)
                    <form method="post" action="{{ route('incidents.acknowledge', $incident->reference) }}" data-queue="Acknowledge {{ $incident->reference }}">
                        @csrf
                        <button class="button" type="submit">Acknowledge</button>
                    </form>
                @endif
                @if (! $incident->resolved_at)
                    <details>
                        <summary class="button secondary">Resolve…</summary>
                        <form method="post" action="{{ route('incidents.resolve', $incident->reference) }}" data-queue="Resolve {{ $incident->reference }}">
                            @csrf
                            <label for="note-{{ $incident->reference }}">What was done (optional)</label>
                            <textarea id="note-{{ $incident->reference }}" name="resolution_note" maxlength="1000"></textarea>
                            <p></p>
                            <button class="button" type="submit">Mark resolved</button>
                        </form>
                    </details>
                @else
                    <form method="post" action="{{ route('incidents.reopen', $incident->reference) }}" data-queue="Reopen {{ $incident->reference }}">
                        @csrf
                        <button class="button secondary" type="submit">Reopen</button>
                    </form>
                @endif
            </div>
            <div class="queue-state" data-queue-state aria-live="polite"></div>
        </li>
    @endforeach
</ul>

<div class="pagination">
    @if ($incidents->previousPageUrl())<a class="button secondary" href="{{ $incidents->previousPageUrl() }}">← Newer</a>@endif
    @if ($incidents->nextPageUrl())<a class="button secondary" href="{{ $incidents->nextPageUrl() }}">Older →</a>@endif
</div>
@endsection
