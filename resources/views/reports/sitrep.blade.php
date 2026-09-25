@extends('layouts.app')

@section('title', 'Situation report')

@php
    $projection = $tracker->projection();
    $links = array_slice($tracker->weakLinks(), 0, 8);
    $focus = $tracker->focusParty();
@endphp

@section('content')
<div class="report">
    <div class="report-actions no-print">
        <button type="button" class="button" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <header class="report-head">
        <p class="report-kicker">Election Shield · situation report{{ $rehearsal ? ' · REHEARSAL DATA' : '' }}</p>
        <h1>{{ \App\Support\Time::local(now(), 'l j F Y, g:i A') }}</h1>
        <p>{{ config('election.name') }}. Figures are our agents' EC8A results (PVT) received so far.</p>
    </header>

    <section class="report-section">
        <h2>Coverage</h2>
        <p><b>{{ number_format($state->reported) }} of {{ number_format($state->units) }}</b> PUs reported ({{ $state->coverage() }}%) · <b>{{ number_format($state->totalVotes()) }}</b> valid votes · turnout at reported PUs {{ $state->turnout() === null ? '—' : $state->turnout().'%' }} · {{ number_format($state->rejected) }} rejected.</p>
    </section>

    <section class="report-section">
        <h2>Votes and the win condition</h2>
        <div class="report-scroll"><table class="compact report-table">
            <thead><tr><th>Party</th><th>Candidate</th><th class="num">Votes</th><th class="num">Share</th><th class="num">LGAs at {{ (int) $tracker->share }}%+</th></tr></thead>
            <tbody>
                @foreach ($tracker->candidates() as $row)
                    <tr><td>{{ $row['party'] }}{{ $row['leading'] ? ' (leading)' : '' }}</td><td>{{ $row['candidate'] }}</td><td class="num">{{ number_format($row['votes']) }}</td><td class="num">{{ $row['share'] }}%</td><td class="num">{{ $row['lgas_met'] }} of {{ $tracker->lgaCount }}{{ $row['meets_spread'] ? ' ✓' : '' }}</td></tr>
                @endforeach
                <tr><td>OTHERS</td><td>all other parties</td><td class="num">{{ number_format($state->votes['OTHERS'] ?? 0) }}</td><td class="num">{{ $state->share('OTHERS') }}%</td><td></td></tr>
            </tbody>
        </table></div>
        <p><b>On current figures:</b>
            @switch($projection['outcome'])
                @case('declared') {{ $projection['party'] }} would be declared (most votes and {{ (int) $tracker->share }}% in at least {{ $tracker->required }} LGAs). @break
                @case('runoff') run-off: {{ $projection['party'] }} leads but has {{ (int) $tracker->share }}% in only {{ $tracker->lgasMet($projection['party']) }} of the {{ $tracker->required }} LGAs needed. @break
                @case('tie') tied at the top. @break
                @default no results yet.
            @endswitch
        </p>
    </section>

    <section class="report-section">
        <h2>Weak links{{ $focus ? ' for '.$focus : '' }}</h2>
        @if ($links === [])
            <p class="small">None.</p>
        @else
            <ul class="small">
                @foreach ($links as $link)
                    <li><b>{{ $link['lga']->name }}</b>: {{ $link['lga']->totalVotes() ? $link['share'].'%' : 'no results' }}, {{ $link['lga']->reported }}/{{ $link['lga']->units }} PUs reported{{ $link['below'] ? ' · below '.(int) $tracker->share.'%' : '' }}{{ $link['near'] ? ' · near '.(int) $tracker->share.'%' : '' }}{{ $link['low_coverage'] ? ' · low coverage' : '' }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="report-section">
        <h2>Field</h2>
        <ul class="small">
            <li>Agents checked in: {{ number_format($field->checkedIn) }} of {{ number_format($field->units) }} PUs ({{ $field->percent($field->checkedIn) }}%)</li>
            <li>Materials: {{ number_format($field->materials['arrived'] ?? 0) }} arrived, {{ number_format($field->materials['incomplete'] ?? 0) }} incomplete, {{ number_format($field->materials['not_arrived'] ?? 0) }} not arrived, {{ number_format($field->materials['none'] ?? 0) }} no report</li>
            <li>Incidents: {{ $incidentsByType->sum('total') }} reported, {{ $incidentsByType->sum('open') }} unresolved, <b>{{ $urgentOpen }} urgent unresolved</b>@if ($incidentsByType->isNotEmpty()) ({{ $incidentsByType->map(fn ($r) => $r->label.' '.$r->total)->implode(', ') }})@endif</li>
            <li>Corrections waiting for review: {{ $pendingCorrections }}</li>
        </ul>
    </section>

    <section class="report-section">
        <h2>Largest differences from IReV</h2>
        @if ($discrepancies->isEmpty())
            <p class="small">None flagged.</p>
        @else
            <ul class="small">
                @foreach ($discrepancies as $row)
                    <li>{{ $row['unit']?->name ?? $row['code'] }} ({{ $row['lga'] }} › {{ $row['ward'] }}): up to {{ $row['max_diff'] }} votes · {{ collect($row['diff'])->filter()->map(fn ($d, $p) => $p.' '.($d > 0 ? '+' : '').$d)->implode(', ') }}</li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
