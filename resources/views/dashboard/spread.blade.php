@extends('layouts.app')

@section('title', '25% rule')

@php
    $candidates = \App\Services\Collation::candidates();
    $threshold = $tracker->share;
@endphp

@section('content')
<div class="page-head">
    <h1>25% tracker</h1>
    <p class="muted">Section 179(2): the winner needs the most votes and at least {{ (int) $threshold }}% of the votes in at least {{ $tracker->required }} of {{ $tracker->lgaCount }} LGAs. Shares are of valid votes, on current figures.</p>
</div>

@include('partials.results-tabs')

<div class="stats">
    @foreach ($tracker->candidates() as $row)
        <div class="stat">
            <b><span class="sw {{ \App\Support\Party::slot($row['party']) }}"></span> {{ $row['lgas_met'] }}<small class="muted"> / {{ $tracker->lgaCount }}</small></b>
            <span>{{ $row['party'] }}: LGAs at {{ (int) $threshold }}%+ (needs {{ $tracker->required }}){{ $row['meets_spread'] ? ' ✓ met' : ', '.$row['lgas_needed'].' short' }}</span>
        </div>
    @endforeach
</div>

@if ($tracker->lgas === [])
    <div class="card"><p class="muted">No LGAs yet. Import the PU register under System, or wait for results.</p></div>
@endif

<div class="grid two">
    @foreach ($tracker->lgas as $lga)
        <section class="card" aria-label="{{ $lga->name }}">
            <h2><a class="rowlink" href="{{ route('collation.lga', $lga->name) }}">{{ $lga->name }}</a></h2>
            <p class="small muted">{{ $lga->reported }} of {{ $lga->units }} PUs reported ({{ $lga->coverage() }}%) · {{ number_format($lga->totalVotes()) }} valid votes</p>
            <ul class="bars">
                @foreach ($candidates as $party)
                    @php($share = $lga->share($party))
                    <li class="bar-row" title="{{ $party }} in {{ $lga->name }}: {{ $share }}%">
                        <span class="who"><span class="sw {{ \App\Support\Party::slot($party) }}"></span>{{ $party }}</span>
                        <span class="val">{{ $lga->totalVotes() ? $share.'%' : '—' }}
                            @if ($lga->totalVotes())
                                @if ($share >= $threshold)<span class="badge good">✓ {{ (int) $threshold }}%</span>@else<span class="badge bad">✗ under</span>@endif
                            @endif
                        </span>
                        <span class="track slim" aria-hidden="true">
                            <span class="fill {{ \App\Support\Party::slot($party) }}" style="width: {{ $share }}%"></span>
                            <span class="mark" style="left: {{ $threshold }}%"></span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
@endsection
