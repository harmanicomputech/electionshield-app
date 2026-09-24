@extends('layouts.app')

@section('title', 'Dashboard')

@php
    $projection = $tracker->projection();
    $focus = $tracker->focusParty();
    $links = $tracker->weakLinks();
@endphp

@section('content')
<div class="page-head">
    <h1>PVT dashboard</h1>
    <p class="muted">{{ config('election.name') }} · {{ \Illuminate\Support\Carbon::parse(config('election.date'))->format('l j F Y') }}</p>
</div>

<div class="stats">
    <div class="stat"><b>{{ number_format($state->reported) }}<small class="muted"> / {{ number_format($state->units) }}</small></b><span>PUs reported ({{ $state->coverage() }}%)</span></div>
    <div class="stat"><b>{{ number_format($state->totalVotes()) }}</b><span>Valid votes counted</span></div>
    <div class="stat"><b>{{ $state->turnout() === null ? '—' : $state->turnout().'%' }}</b><span>Turnout at reported PUs</span></div>
    <div class="stat"><b>{{ number_format($state->rejected) }}</b><span>Rejected votes</span></div>
</div>

@if ($duplicates > 0)
    <div class="flash bad" role="alert">{{ $duplicates }} PU(s) have more than one accepted result; the latest is counted. Run a full import under System to repair.</div>
@endif

<div class="grid two">
    <section class="card" aria-labelledby="h-win">
        <h2 id="h-win">Win condition on current figures</h2>
        @php($leader = $projection['party'])
        <div class="verdict">
            @switch($projection['outcome'])
                @case('none')
                    <span class="icon" aria-hidden="true">⏳</span>
                    <div><b>No results yet.</b><p class="muted small">Figures appear as agents submit EC8A results by USSD.</p></div>
                    @break
                @case('tie')
                    <span class="icon" aria-hidden="true">⚖️</span>
                    <div><b>Tied at the top.</b></div>
                    @break
                @case('declared')
                    <span class="icon" aria-hidden="true">✅</span>
                    <div><b>{{ $leader }} would be declared.</b><p class="muted small">Highest votes and at least {{ (int) $tracker->share }}% in {{ $tracker->lgasMet($leader) }} of {{ $tracker->lgaCount }} LGAs (needs {{ $tracker->required }}).</p></div>
                    @break
                @case('runoff')
                    <span class="icon" aria-hidden="true">⚠️</span>
                    <div><b>Run-off on current figures.</b><p class="muted small">{{ $leader }} leads but has {{ (int) $tracker->share }}% in only {{ $tracker->lgasMet($leader) }} of {{ $tracker->required }} required LGAs.</p></div>
                    @break
            @endswitch
        </div>
        <p></p>
        <ul class="bars">
            @foreach ($tracker->candidates() as $row)
                <li class="bar-row" title="{{ $row['party'] }}: {{ $row['lgas_met'] }} of {{ $tracker->lgaCount }} LGAs at {{ (int) $tracker->share }}%">
                    <span class="who"><span class="sw {{ \App\Support\Party::slot($row['party']) }}"></span>{{ $row['party'] }}
                        @if ($row['leading'])<span class="badge">Leading</span>@endif
                    </span>
                    <span class="val">{{ $row['lgas_met'] }} <small>of {{ $tracker->lgaCount }} LGAs</small>
                        @if ($row['meets_spread'])<span class="badge good">✓ Met</span>@endif
                    </span>
                    <span class="track slim" aria-hidden="true">
                        <span class="fill {{ \App\Support\Party::slot($row['party']) }}" style="width: {{ round(100 * $row['lgas_met'] / max(1, $tracker->lgaCount), 1) }}%"></span>
                        <span class="mark" style="left: {{ round(100 * $tracker->required / max(1, $tracker->lgaCount), 1) }}%"></span>
                    </span>
                </li>
            @endforeach
        </ul>
        <p class="small muted" style="margin-top:12px">LGAs where each candidate has at least {{ (int) $tracker->share }}%. The line marks the {{ $tracker->required }} needed. <a href="{{ route('spread') }}">See every LGA →</a></p>
    </section>

    <section class="card" aria-labelledby="h-totals">
        <h2 id="h-totals">Votes by party</h2>
        @include('partials.party-bars', ['tally' => $state])
        <p class="small muted" style="margin-top:12px">Accepted results only. <a href="{{ route('collation') }}">Collation by LGA →</a></p>
    </section>
</div>

<section class="card" aria-labelledby="h-weak" style="margin-top:16px">
    <h2 id="h-weak">Weak links @if ($focus)<span class="muted small">for {{ $focus }}</span>@endif</h2>
    @if ($links === [])
        <p class="muted">None: every LGA is above {{ (int) $tracker->share + (int) config('election.near_margin') }}% with good coverage.</p>
    @else
        <div class="table-wrap">
            <table class="stack">
                <thead><tr><th>LGA</th><th class="num">{{ $focus ?? 'Share' }} share</th><th class="num">PUs reported</th><th>Why</th></tr></thead>
                <tbody>
                    @foreach ($links as $link)
                        <tr>
                            <td class="key"><a class="rowlink" href="{{ route('collation.lga', $link['lga']->name) }}">{{ $link['lga']->name }}</a></td>
                            <td class="num" data-label="{{ $focus ?? 'Share' }} share">{{ $link['lga']->totalVotes() ? $link['share'].'%' : '—' }}</td>
                            <td class="num" data-label="PUs reported">{{ $link['lga']->reported }} / {{ $link['lga']->units }} ({{ $link['lga']->coverage() }}%)</td>
                            <td data-label="Why">
                                @if ($link['below'])<span class="badge bad">Below {{ (int) $tracker->share }}%</span>@endif
                                @if ($link['near'])<span class="badge warn">Near {{ (int) $tracker->share }}%</span>@endif
                                @if ($link['low_coverage'])<span class="badge warn">Low coverage</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
