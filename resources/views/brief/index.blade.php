@extends('layouts.app')

@section('title', 'Situation brief')

@php
    $tz = config('election.timezone', 'Africa/Lagos');
    $n = fn ($value) => number_format((int) $value);
@endphp

@section('content')
<div class="page-head">
    <h1>Situation brief</h1>
    <p class="muted">A short brief on election day, written by AI from this app's live figures every {{ $every }} minutes while reports are coming in (and sent to phones that turned on “Situation brief” notifications). It uses only the figures below; check anything important against the source pages.</p>
</div>

@if ($aiOn)
    <form method="post" action="{{ route('brief.store') }}" class="row-actions" style="margin-bottom:16px">
        @csrf
        <button class="button" type="submit">Write a brief now</button>
    </form>
@else
    <div class="card"><p class="small">AI is off, so no brief is written: set ANTHROPIC_API_KEY in .env and switch AI assistance on on the System page. The live figures below are always up to date.</p></div>
@endif

@forelse ($briefs as $brief)
    <section class="card brief {{ $loop->first ? '' : 'older' }}" id="brief-{{ $brief->id }}">
        <p class="small muted">{{ $brief->created_at->timezone($tz)->format('D j M, g:i A') }} · {{ $brief->written_by === 'Automatic' ? 'automatic' : 'asked for by '.$brief->written_by }}@if ($brief->rehearsal) · <span class="badge warn">Rehearsal data</span>@endif</p>
        <h2>{{ $brief->headline }}</h2>
        @if ($loop->first || count($brief->points) <= 3)
            <ul>@foreach ($brief->points as $point)<li>{{ $point }}</li>@endforeach</ul>
            @if ($brief->actions)
                <p class="small" style="margin:8px 0 0"><b>Do next</b></p>
                <ul class="brief-actions">@foreach ($brief->actions as $action)<li>{{ $action }}</li>@endforeach</ul>
            @endif
        @else
            <details>
                <summary class="small">Show the full brief</summary>
                <ul>@foreach ($brief->points as $point)<li>{{ $point }}</li>@endforeach</ul>
                @if ($brief->actions)<ul class="brief-actions">@foreach ($brief->actions as $action)<li>{{ $action }}</li>@endforeach</ul>@endif
            </details>
        @endif
    </section>
@empty
    <div class="card"><p class="muted">No brief yet. {{ $aiOn ? 'One is written automatically once reports start coming in, or press “Write a brief now”.' : '' }}</p></div>
@endforelse

<section class="card facts">
    <h2>Live figures</h2>
    <dl class="small">
        <dt>Time</dt><dd>{{ $facts['time'] }}</dd>
        <dt>Agents checked in</dt><dd>{{ $n($facts['checked_in']['count']) }} of {{ $n($facts['polling_units']) }} PUs ({{ $facts['checked_in']['percent'] }}%)</dd>
        <dt>Materials</dt><dd>{{ $n($facts['materials']['arrived'] ?? 0) }} arrived, {{ $n($facts['materials']['incomplete'] ?? 0) }} incomplete, {{ $n($facts['materials']['not_arrived'] ?? 0) }} not arrived</dd>
        <dt>Results in</dt><dd>{{ $n($facts['results']['count']) }} PUs ({{ $facts['results']['percent'] }}%)@if ($facts['votes']) · {{ collect($facts['votes'])->map(fn ($v, $p) => "{$p} {$v['share']}%")->implode(', ') }}@endif · <a href="{{ route('collation') }}">Collation →</a></dd>
        <dt>Incidents</dt><dd>{{ $n($facts['incidents']['open']) }} open, {{ $n($facts['incidents']['last_hour']) }} in the last hour · <a href="{{ route('incidents') }}">Incidents →</a></dd>
        @if ($facts['incidents']['clusters'])<dt>Clusters</dt><dd>{{ implode('; ', $facts['incidents']['clusters']) }}</dd>@endif
        <dt>Results flagged</dt><dd>{{ $n($facts['flagged_results']['serious']) }} serious, {{ $n($facts['flagged_results']['to_review']) }} to check · <a href="{{ route('result-checks') }}">Result checks →</a></dd>
        @if ($facts['lgas_least_checked_in'])<dt>Least checked in</dt><dd>{{ implode('; ', $facts['lgas_least_checked_in']) }}</dd>@endif
        <dt>PUs needing attention</dt><dd>{{ $n($facts['pus_needing_attention']) }} · <a href="{{ route('monitor') }}">Polling units →</a></dd>
    </dl>
</section>
@endsection
