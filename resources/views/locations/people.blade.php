@extends('layouts.app')

@section('title', 'People map')

@section('head')
    @include('locations._map-assets')
@endsection

@php
    $statusInfo = [
        'located' => ['good', 'Located', 'Shared their location'],
        'outside' => ['bad', 'Outside the state', 'Last position outside Ebonyi'],
        'unavailable' => ['warn', 'No GPS', 'Phone could not get a position'],
        'denied' => ['bad', 'Location refused', 'Refused to share location'],
        'not_seen' => ['', 'Not seen', 'Did not open the app'],
    ];
    $rangeLabel = \App\Services\PeopleLocations::RANGES[$range][0];
    $keep = array_filter(['group' => $group !== 'all' ? $group : null, 'lga' => $lga, 'q' => $search !== '' ? $search : null, 'status' => $status !== 'all' ? $status : null]);
    $initials = fn (string $name) => collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
@endphp

@section('content')
<div class="page-head">
    <h1>People map</h1>
    <p class="muted">Where agents and coordinators were, from their phones: when they open the app, every 10 minutes while it is open, and with each action. Tap a person for their full history.</p>
</div>

@include('locations._tabs')

<div class="people-toolbar">
    @include('locations._range', ['routeName' => 'locations.people', 'keep' => $keep])
</div>

<div class="stats loc-stats">
    <a class="stat {{ $status === 'all' ? 'on' : '' }}" href="{{ route('locations.people', [...$keep, 'range' => $range, 'status' => null]) }}"><b>{{ number_format($total) }}</b><span>People tracked</span></a>
    @foreach ($statusInfo as $key => [$class, $label, $hint])
        <a class="stat {{ $status === $key ? 'on' : '' }} {{ $class ? 'tone-'.$class : '' }}" href="{{ route('locations.people', [...$keep, 'range' => $range, 'status' => $status === $key ? null : $key]) }}" title="{{ $hint }}">
            <b>{{ number_format($counts[$key] ?? 0) }}</b><span>{{ $label }}</span>
        </a>
    @endforeach
</div>

<section class="card map-card">
    <div class="map-head">
        <h2>Last known positions <span class="muted small">· {{ $rangeLabel }}</span></h2>
        <ul class="map-legend" aria-label="Legend">
            <li><i class="dot agent"></i>Agents</li>
            <li><i class="dot coordinator"></i>Coordinators</li>
            <li><i class="dot other"></i>Others</li>
            <li><i class="dot outside"></i>Outside the state</li>
        </ul>
    </div>
    @if ($markers->isEmpty())
        <p class="map-empty muted">No one shared a position in this time frame{{ $status !== 'all' || $group !== 'all' || $lga || $search !== '' ? ' with these filters' : '' }}.</p>
    @endif
    <div class="map-canvas" data-people-map data-source="people-map-data" role="region" aria-label="Map of last known positions"></div>
    <p class="map-note small muted">{{ number_format($markers->count()) }} on the map. Circles show the last position in the time frame; tap one for details.</p>
    <script type="application/json" id="people-map-data">@json(['markers' => $markers, 'bounds' => $bounds])</script>
</section>

<form class="filters people-filters" method="get" action="{{ route('locations.people') }}">
    <input type="hidden" name="range" value="{{ $range }}">
    @if ($status !== 'all')<input type="hidden" name="status" value="{{ $status }}">@endif
    <div>
        <label for="group">Who</label>
        <select id="group" name="group" data-autosubmit>
            @foreach (['all' => 'Everyone', 'agent' => 'Agents', 'coordinator' => 'Coordinators', 'other' => 'Other roles'] as $key => $label)
                <option value="{{ $key }}" @selected($group === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="lga">LGA</label>
        <select id="lga" name="lga" data-autosubmit>
            <option value="">All LGAs</option>
            @foreach ($lgas as $option)
                <option value="{{ $option }}" @selected($lga === $option)>{{ $option }}</option>
            @endforeach
        </select>
    </div>
    <div class="wide">
        <label for="q">Find a person</label>
        <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Name or phone number">
    </div>
    <div class="wide"><button class="button secondary" type="submit">Search</button></div>
</form>

<h2 class="list-title">{{ number_format($listedCount) }} {{ $listedCount === 1 ? 'person' : 'people' }}@if ($status !== 'all') · {{ $statusInfo[$status][1] }}@endif</h2>

@if ($rows->isEmpty())
    <div class="card"><p class="muted">No one here.</p></div>
@endif

<ul class="list two people-list">
    @foreach ($rows as $row)
        @php
            $someone = $row['user'];
            [$class, $label] = $statusInfo[$row['status']];
            $fix = $row['fix'];
            $seen = $row['latest'] ?? $row['ever'];
        @endphp
        <li class="item person-row" data-person="{{ $someone->id }}">
            <a class="person-link" href="{{ route('locations.person', ['user' => $someone, 'range' => $range]) }}">
                <span class="avatar role-{{ $someone->isAgent() ? 'agent' : ($someone->role === 'coordinator' ? 'coordinator' : 'other') }}" aria-hidden="true">{{ $initials($someone->name) }}</span>
                <span class="who">
                    <b>{{ $someone->name }}</b>
                    <small>{{ $someone->roleName() }}@if ($someone->lga) · {{ $someone->lga }}@endif</small>
                </span>
                <span class="badge {{ $class }}">{{ $label }}</span>
            </a>
            <div class="meta">
                @if ($seen)
                    Last seen {{ $seen->created_at->diffForHumans() }} ({{ \App\Support\Time::local($seen->created_at, 'j M, g:i A') }}) · {{ $seen->action }}
                @else
                    Never opened the app since tracking started
                @endif
            </div>
            @if ($fix)
                <div class="meta">
                    Position {{ number_format($fix->latitude, 5) }}, {{ number_format($fix->longitude, 5) }}@if ($fix->accuracy !== null) · ±{{ number_format($fix->accuracy) }} m @endif
                    · {{ $row['points'] }} {{ $row['points'] === 1 ? 'reading' : 'readings' }} in this time frame
                </div>
            @endif
            <div class="actions row-actions">
                <a class="button secondary" href="{{ route('locations.person', ['user' => $someone, 'range' => $range]) }}">Location history</a>
                @if ($fix)<button class="button secondary" type="button" data-focus-person="{{ $someone->id }}">Show on map</button>@endif
                @if ($phones && $someone->phone)<a class="button secondary" href="tel:{{ $someone->phone }}">Call</a>@endif
            </div>
        </li>
    @endforeach
</ul>

@if ($pages > 1)
    <div class="pagination">
        @if ($page > 1)<a class="button secondary" href="{{ route('locations.people', [...$keep, 'range' => $range, 'page' => $page - 1]) }}">← Previous</a>@endif
        <span class="muted small">Page {{ $page }} of {{ $pages }}</span>
        @if ($page < $pages)<a class="button secondary" href="{{ route('locations.people', [...$keep, 'range' => $range, 'page' => $page + 1]) }}">Next →</a>@endif
    </div>
@endif
@endsection
