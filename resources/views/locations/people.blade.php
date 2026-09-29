@extends('layouts.app')

@section('title', 'Locations')

@section('content')
<div class="page-head">
    <h1>Locations</h1>
    <p class="muted">Where people whose role has “Location is recorded” were when they opened the app, while it was open (every 10 minutes) and when they did something in it. Admins are not tracked.</p>
</div>

<nav class="tabs" aria-label="Locations">
    <a href="{{ route('locations') }}">Agents' check-ins</a>
    <a href="{{ route('locations', ['tab' => 'people']) }}" class="on">People</a>
</nav>

@if ($person)
    <div class="card">
        <h2>{{ $person->name }} <span class="muted">· {{ $person->roleName() }}</span></h2>
        <p><a href="{{ route('locations', ['tab' => 'people']) }}">← Everyone</a></p>
        @if ($history->isEmpty())
            <p class="muted">Nothing recorded yet.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>When</th><th>What</th><th>Where</th></tr></thead>
                    <tbody>
                        @foreach ($history as $entry)
                            <tr>
                                <td>{{ \App\Support\Time::local($entry->created_at, 'j M, g:i:s A') }}</td>
                                <td>{{ $entry->action }}</td>
                                <td>
                                    @if ($entry->status === \App\Models\UserLocation::OK)
                                        <a href="{{ \App\Support\Geo::mapLink($entry->latitude, $entry->longitude) }}" target="_blank" rel="noopener">{{ number_format($entry->latitude, 5) }}, {{ number_format($entry->longitude, 5) }}</a>
                                        @if ($entry->accuracy !== null)<span class="muted">±{{ number_format($entry->accuracy) }} m</span>@endif
                                        @unless (\App\Support\Geo::inState($entry->latitude, $entry->longitude))<span class="badge bad">Outside the state</span>@endunless
                                    @elseif ($entry->status === \App\Models\UserLocation::DENIED)
                                        <span class="badge bad">Location refused</span>
                                    @else
                                        <span class="badge warn">Location unavailable</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@else
    @if ($people->isEmpty())
        <div class="card"><p class="muted">No one's role has “Location is recorded”.</p></div>
    @endif
    <ul class="list two">
        @foreach ($people as $someone)
            @php($last = $latest[$someone->id] ?? null)
            @php($fix = $lastFix[$someone->id] ?? null)
            <li class="item {{ $last && $last->status === \App\Models\UserLocation::DENIED ? 'urgent' : '' }}">
                <div class="badges" style="margin-top:0">
                    <span class="badge">{{ $someone->roleName() }}</span>
                    @if (! $last)
                        <span class="badge warn">Not seen yet</span>
                    @elseif ($last->status === \App\Models\UserLocation::DENIED)
                        <span class="badge bad">Location not shared</span>
                    @elseif ($last->status === \App\Models\UserLocation::UNAVAILABLE)
                        <span class="badge warn">Location unavailable</span>
                    @elseif (! \App\Support\Geo::inState($last->latitude, $last->longitude))
                        <span class="badge bad">Outside the state</span>
                    @else
                        <span class="badge good">Location shared</span>
                    @endif
                </div>
                <h3><a href="{{ route('locations', ['tab' => 'people', 'user' => $someone->id]) }}">{{ $someone->name }}</a></h3>
                @if ($someone->lga)<div class="meta">{{ $someone->lga }}</div>@endif
                @if ($last)<div class="meta">Last seen {{ \App\Support\Time::local($last->created_at, 'j M, g:i A') }} ({{ $last->action }})</div>@endif
                @if ($fix)
                    <div class="meta"><a href="{{ \App\Support\Geo::mapLink($fix->latitude, $fix->longitude) }}" target="_blank" rel="noopener">Last known position (map)</a> · {{ \App\Support\Time::local($fix->created_at, 'j M, g:i A') }}@if ($fix->accuracy !== null) · ±{{ number_format($fix->accuracy) }} m @endif</div>
                @endif
            </li>
        @endforeach
    </ul>
@endif
@endsection
