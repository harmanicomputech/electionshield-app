@extends('layouts.app')

@section('title', 'Agents')

@php
    $materialBadge = ['arrived' => ['good', '✓ Materials'], 'incomplete' => ['warn', '! Materials incomplete'], 'not_arrived' => ['bad', '✗ No materials']];
@endphp

@section('content')
<div class="page-head">
    <h1>Agents</h1>
    <p class="muted">Who to call. Tap a number to ring the agent. The lists follow what agents have sent by USSD.</p>
</div>

<nav class="tabs" aria-label="Agent lists">
    @foreach (\App\Http\Controllers\Console\AgentController::VIEWS as $key => $label)
        <a href="{{ route('agents', [...request()->except('view', 'page'), 'view' => $key]) }}" class="{{ $view === $key ? 'on' : '' }}">{{ $label }} <b>{{ number_format($counts[$key]) }}</b></a>
    @endforeach
</nav>

<form class="filters" method="get" action="{{ route('agents') }}">
    <input type="hidden" name="view" value="{{ $view }}">
    <div><label for="a-lga">LGA</label><select id="a-lga" name="lga" data-autosubmit><option value="all">All LGAs</option>@foreach ($lgas as $lga)<option @selected(request()->attributes->get('agents_lga') === $lga)>{{ $lga }}</option>@endforeach</select></div>
    <div><label for="a-q">Search name, phone or PU</label><input id="a-q" type="text" name="q" value="{{ request('q') }}"></div>
    <div><button class="button secondary" type="submit">Search</button></div>
    @if (auth()->user()->isAdmin())
        <div><a class="button secondary" href="{{ route('agents.export', request()->query()) }}">Download this list (CSV)</a></div>
    @endif
</form>

@if ($rows->isEmpty())
    <div class="card"><p class="muted">{{ $view === 'all' ? 'No agents yet: they come from the USSD service (System → Full import).' : 'Nobody in this list.' }}</p></div>
@endif

<ul class="list two">
    @foreach ($rows as $row)
        @php($s = $row['status'])
        <li class="item {{ $row['agents']->isEmpty() ? 'urgent' : '' }}">
            <h3>{{ $s->name() }}</h3>
            <div class="meta">{{ $s->unit?->inecCode() ?? $s->code }} · <a href="{{ route('monitor.ward', [$s->lga, $s->ward]) }}">{{ $s->lga }} › {{ $s->ward }}</a></div>
            <div class="badges">
                @if ($s->checkedInAt)<span class="badge good">✓ Checked in {{ \App\Support\Time::local($s->checkedInAt) }}</span>@else<span class="badge warn">No check-in</span>@endif
                @if ($s->materials)@php([$c, $t] = $materialBadge[$s->materials->status] ?? ['', $s->materials->status])<span class="badge {{ $c }}">{{ $t }}</span>@endif
                @if ($s->result)<span class="badge good">✓ {{ $s->result->reference }}</span>@else<span class="badge">No result</span>@endif
                @if ($s->urgentIncidents)<span class="badge bad">⚠ {{ $s->urgentIncidents }} urgent</span>@endif
            </div>
            @forelse ($row['agents'] as $agent)
                <div class="agent-line">
                    <span><b>{{ $agent->name }}</b>@if ($agent->locked) <span class="badge bad">PIN locked</span>@endif<span class="muted small" style="display:block">{{ $agent->last_seen_at ? 'Last on USSD '.\App\Support\Time::local($agent->last_seen_at, 'j M, g:i A') : 'Not seen on USSD yet' }}</span></span>
                    <a class="button secondary" href="tel:{{ $agent->phone_number }}">Call</a>
                </div>
            @empty
                <p class="small" style="margin:6px 0 0"><b>No agent registered for this PU.</b> Register one in the USSD console.</p>
            @endforelse
        </li>
    @endforeach
</ul>

<div class="pagination">
    @if ($rows->previousPageUrl())<a class="button secondary" href="{{ $rows->previousPageUrl() }}">← Previous</a>@endif
    <span class="muted small">Page {{ $rows->currentPage() }} of {{ max(1, $rows->lastPage()) }}</span>
    @if ($rows->nextPageUrl())<a class="button secondary" href="{{ $rows->nextPageUrl() }}">Next →</a>@endif
</div>
@endsection
