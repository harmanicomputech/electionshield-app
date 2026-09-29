@extends('layouts.app')

@section('title', $person->name.' · Location history')

@section('head')
    @include('locations._map-assets')
@endsection

@php
    $rangeLabel = \App\Services\PeopleLocations::RANGES[$range][0];
    $initials = collect(preg_split('/\s+/', trim($person->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $days = $history['steps']->reverse()->groupBy(fn ($step) => \App\Support\Time::local($step['entry']->created_at, 'l j F Y'));
    $unit = $history['unit'];
    $last = $history['lastEver'];
    $distance = $history['distance'];
@endphp

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('locations.people', ['range' => $range]) }}">← People map</a></div>
    <div class="person-head">
        <span class="avatar big role-{{ $group }}" aria-hidden="true">{{ $initials }}</span>
        <div>
            <h1>{{ $person->name }}</h1>
            <p class="muted">
                {{ $person->roleName() }}@if ($person->lga) · {{ $person->lga }}@endif
                @if ($phones && $person->phone) · <a class="tel" href="tel:{{ $person->phone }}">{{ $person->phone }}</a>@endif
                @if ($unit) · PU {{ $unit->name }} ({{ $unit->code }})@endif
            </p>
            <p class="small">
                @if ($last)
                    Last seen {{ $last->created_at->diffForHumans() }}, {{ \App\Support\Time::local($last->created_at, 'j M, g:i A') }} ({{ $last->action }})
                    @if ($last->status === \App\Models\UserLocation::DENIED)<span class="badge bad">Location refused</span>@endif
                @else
                    <span class="badge">Never seen</span>
                @endif
            </p>
        </div>
    </div>
</div>

@include('locations._tabs')

<div class="people-toolbar">
    @include('locations._range', ['routeName' => 'locations.person', 'keep' => ['user' => $person->id]])
    <a class="button secondary" href="{{ route('locations.person.csv', ['user' => $person, 'range' => $range]) }}">Download CSV</a>
</div>

<div class="stats loc-stats">
    <div class="stat"><b>{{ number_format($history['steps']->count()) }}</b><span>Readings</span></div>
    <div class="stat"><b>{{ number_format($history['fixes']->count()) }}</b><span>Positions</span></div>
    <div class="stat"><b>{{ $distance >= 1000 ? number_format($distance / 1000, 1).' km' : number_format($distance).' m' }}</b><span>Moved (about)</span></div>
    <div class="stat {{ $history['refused'] ? 'tone-bad' : '' }}"><b>{{ number_format($history['refused']) }}</b><span>Times refused</span></div>
    <div class="stat {{ $history['outside'] ? 'tone-bad' : '' }}"><b>{{ number_format($history['outside']) }}</b><span>Outside the state</span></div>
    <div class="stat"><b>{{ $history['first'] ? \App\Support\Time::local($history['first']->created_at, 'g:i A') : '–' }}</b><span>First reading{{ $history['first'] ? ' · '.\App\Support\Time::local($history['first']->created_at, 'j M') : '' }}</span></div>
</div>

<section class="card map-card">
    <div class="map-head">
        <h2>Movement <span class="muted small">· {{ $rangeLabel }}</span></h2>
        <ul class="map-legend" aria-label="Legend">
            <li><i class="dot start"></i>First</li>
            <li><i class="dot {{ $group }}"></i>Positions</li>
            <li><i class="dot end"></i>Latest</li>
            @if ($unit?->latitude !== null)<li><i class="square"></i>Their PU</li>@endif
        </ul>
    </div>
    @if ($history['fixes']->isEmpty())
        <p class="map-empty muted">No positions in this time frame. Try a longer one.</p>
    @endif
    <div class="map-canvas" data-people-map data-source="person-map-data" role="region" aria-label="Map of {{ $person->name }}'s positions"></div>
    <script type="application/json" id="person-map-data">@json($mapData)</script>
    @if ($history['truncated'])<p class="small muted">Showing the first 3,000 readings; choose a shorter time frame for the rest.</p>@endif
</section>

@if ($history['agent'])
    <section class="card">
        <h2>Check-ins at the PU</h2>
        @if (! $unit)
            <p class="muted">No polling unit assigned.</p>
        @elseif ($unit->latitude === null)
            <p class="small muted">The PU's location is not set yet, so distances can't be measured. Set it from a trusted check-in on the <a href="{{ route('locations') }}">check-ins tab</a>.</p>
        @endif
        @forelse ($history['checkins'] as $checkin)
            @php($check = $verdicts[$checkin->id])
            <div class="checkin-line">
                <span class="badge {{ $check['class'] }}">{{ $check['label'] }}</span>
                <span>{{ \App\Support\Time::local($checkin->confirmed_at, 'j M, g:i A') }} · via {{ $checkin->channel === 'web' ? 'web app' : 'USSD' }}</span>
                @if ($checkin->hasLocation())<a href="{{ \App\Support\Geo::mapLink($checkin->latitude, $checkin->longitude) }}" target="_blank" rel="noopener">map</a>@endif
            </div>
        @empty
            <p class="muted small">No check-ins in this time frame.</p>
        @endforelse
    </section>
@endif

<section class="timeline" aria-label="Location history">
    <h2>History <span class="muted small">· newest first</span></h2>
    @if ($days->isEmpty())
        <div class="card"><p class="muted">Nothing recorded in this time frame.</p></div>
    @endif
    @foreach ($days as $day => $steps)
        <h3 class="timeline-day">{{ $day }} <span class="muted small">· {{ $steps->count() }}</span></h3>
        <ol class="timeline-list">
            @foreach ($steps as $step)
                @php($entry = $step['entry'])
                <li class="timeline-item status-{{ $entry->status }} {{ $step['outside'] ? 'is-outside' : '' }}" @if ($entry->status === \App\Models\UserLocation::OK) data-point="{{ $entry->id }}" tabindex="0" role="button" aria-label="Show {{ \App\Support\Time::local($entry->created_at, 'g:i A') }} on the map" @endif>
                    <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ \App\Support\Time::local($entry->created_at, 'g:i:s A') }}</time>
                    <div class="what">
                        <b>{{ ucfirst($entry->action) }}</b>
                        @if ($entry->status === \App\Models\UserLocation::OK)
                            <span class="meta">
                                {{ number_format($entry->latitude, 5) }}, {{ number_format($entry->longitude, 5) }}@if ($entry->accuracy !== null) · ±{{ number_format($entry->accuracy) }} m @endif
                                @if ($step['moved'] !== null && $step['moved'] >= 50) · moved {{ \App\Support\Geo::distanceLabel($step['moved']) }} @endif
                                · <a href="{{ \App\Support\Geo::mapLink($entry->latitude, $entry->longitude) }}" target="_blank" rel="noopener">Google Maps</a>
                            </span>
                            @if ($step['outside'])<span class="badge bad">Outside the state</span>@endif
                        @elseif ($entry->status === \App\Models\UserLocation::DENIED)
                            <span class="badge bad">Location refused</span>
                        @else
                            <span class="badge warn">No GPS position</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endforeach
</section>
@endsection
