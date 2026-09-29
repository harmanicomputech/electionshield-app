@extends('layouts.app')

@section('title', 'Locations')

@php
    $filters = [
        'all' => ['All check-ins', $total],
        \App\Services\CheckinVerdict::AWAY => ['Away from PU', $counts[\App\Services\CheckinVerdict::AWAY] ?? 0],
        \App\Services\CheckinVerdict::SUSPICIOUS => ['Suspicious', $counts[\App\Services\CheckinVerdict::SUSPICIOUS] ?? 0],
        \App\Services\CheckinVerdict::NO_LOCATION => ['No location', $counts[\App\Services\CheckinVerdict::NO_LOCATION] ?? 0],
        \App\Services\CheckinVerdict::UNKNOWN_PU => ['PU location not set', $counts[\App\Services\CheckinVerdict::UNKNOWN_PU] ?? 0],
        \App\Services\CheckinVerdict::AT_PU => ['At the PU', $counts[\App\Services\CheckinVerdict::AT_PU] ?? 0],
    ];
@endphp

@section('content')
<div class="page-head">
    <h1>Locations</h1>
    <p class="muted">Where agents were when they checked in, measured against their polling unit (allowed: {{ $radius }} m plus the phone's GPS error). Agents are not shown these figures.</p>
</div>

<nav class="tabs" aria-label="Locations">
    <a href="{{ route('locations') }}" class="on">Agents' check-ins</a>
    <a href="{{ route('locations', ['tab' => 'people']) }}">People</a>
</nav>

<nav class="tabs" aria-label="Verdict">
    @foreach ($filters as $key => [$label, $count])
        <a href="{{ route('locations', ['verdict' => $key]) }}" class="{{ $verdict === $key ? 'on' : '' }}">{{ $label }} <b>{{ $count }}</b></a>
    @endforeach
</nav>

@if ($rows->isEmpty())
    <div class="card"><p class="muted">No check-ins here.</p></div>
@endif

<ul class="list two">
    @foreach ($rows as $row)
        @php($presence = $row['presence'])
        @php($check = $row['verdict'])
        <li class="item {{ $check['class'] === 'bad' && ! $presence->location_reviewed_at ? 'urgent' : '' }}" id="checkin-{{ $presence->id }}">
            <div class="badges" style="margin-top:0">
                <span class="badge {{ $check['class'] }}">{{ $check['label'] }}</span>
                <span class="badge {{ $presence->channel === 'web' ? 'channel-web' : '' }}">via {{ $presence->channel === 'web' ? 'web app' : 'USSD' }}</span>
                @if ($presence->location_reviewed_at)<span class="badge good">✓ Reviewed</span>@endif
            </div>
            <h3>{{ $presence->agent_name ?? 'An agent' }}</h3>
            <div class="meta">
                {{ $presence->pollingUnit?->name ?? 'PU '.$presence->polling_unit_code }} · {{ $presence->lga }}@if ($presence->ward) › {{ $presence->ward }}@endif
            </div>
            <div class="meta">Checked in {{ \App\Support\Time::local($presence->confirmed_at, 'j M, g:i:s A') }}@if ($presence->agent_phone && auth()->user()->can('view_agents')) · <a class="tel" href="tel:{{ $presence->agent_phone }}">Call {{ $presence->agent_phone }}</a>@endif</div>
            @if ($presence->hasLocation())
                <div class="meta">
                    <a href="{{ \App\Support\Geo::mapLink($presence->latitude, $presence->longitude) }}" target="_blank" rel="noopener">Where they were (map)</a>
                    · {{ number_format($presence->latitude, 6) }}, {{ number_format($presence->longitude, 6) }}
                    @if ($presence->location_accuracy !== null) · GPS within {{ number_format($presence->location_accuracy) }} m @endif
                    @if ($presence->located_at) · position at {{ \App\Support\Time::local($presence->located_at, 'g:i:s A') }} @endif
                </div>
                @if ($presence->pollingUnit?->latitude !== null)
                    <div class="meta"><a href="{{ \App\Support\Geo::mapLink($presence->pollingUnit->latitude, $presence->pollingUnit->longitude) }}" target="_blank" rel="noopener">The PU (map)</a> · location from {{ $presence->pollingUnit->location_source ?: 'the register' }}</div>
                @endif
            @endif
            @if ($presence->location_reviewed_at)
                <p class="response">Reviewed by {{ $presence->location_reviewed_by }} at {{ \App\Support\Time::local($presence->location_reviewed_at) }}.</p>
            @endif
            <div class="actions">
                @if (! $presence->location_reviewed_at && $check['class'] === 'bad')
                    <form method="post" action="{{ route('locations.review', $presence) }}">
                        @csrf
                        <button class="button" type="submit">Mark reviewed</button>
                    </form>
                @endif
                @if ($presence->hasLocation() && $presence->pollingUnit && $check['status'] !== \App\Services\CheckinVerdict::AT_PU)
                    <form method="post" action="{{ route('locations.set-pu', $presence) }}" onsubmit="return confirm(@js('Use this check-in\'s position as the location of '.$presence->pollingUnit->name.'? Check the map first: every check-in at this PU will be measured against it.'))">
                        @csrf
                        <button class="button secondary" type="submit">Use as the PU's location</button>
                    </form>
                @endif
            </div>
        </li>
    @endforeach
</ul>
@endsection
