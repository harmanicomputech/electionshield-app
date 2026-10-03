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
    <p class="muted">Reported by agents by USSD or the web app. Urgent ones also text the coordinators. @if ($urgentOpen)<b>{{ $urgentOpen }} urgent {{ $urgentOpen === 1 ? 'incident needs' : 'incidents need' }} acknowledging.</b>@endif</p>
</div>

<nav class="tabs" aria-label="Status">
    @foreach ($tabs as $key => [$label, $count])
        <a href="{{ route('incidents', [...$query, 'status' => $key]) }}" class="{{ $status === $key ? 'on' : '' }}">{{ $label }} <b>{{ $count }}</b></a>
    @endforeach
</nav>

@include('partials.lga-map', ['map' => $map, 'title' => 'Where'])

@if ($clusters->isNotEmpty())
    <section class="card ai-clusters" aria-label="Clusters">
        <h2>Several reports from one place</h2>
        <ul>
            @foreach ($clusters as $cluster)
                <li><b>{{ $cluster['count'] }} reports in {{ $cluster['ward'] }} ward, {{ $cluster['lga'] }}</b> in the last hour: {{ collect($cluster['types'])->map(fn ($n, $type) => $n > 1 ? "{$type} ×{$n}" : $type)->implode(', ') }}. <a href="{{ route('monitor.ward', [$cluster['lga'], $cluster['ward']]) }}">Ward status →</a></li>
            @endforeach
        </ul>
    </section>
@endif

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
            <option value="all">All LGAs</option>
            @foreach ($lgas as $option)
                <option value="{{ $option }}" @selected($lga === $option)>{{ $option }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="source">Reported by</label>
        <select id="source" name="source" data-autosubmit>
            <option value="">Agents and the public</option>
            <option value="agent" @selected($source === 'agent')>Agents only</option>
            <option value="public" @selected($source === 'public')>The public only{{ $publicOpen ? " ({$publicOpen} unresolved)" : '' }}</option>
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
                @if ($incident->isPublic())<span class="badge warn" title="Reported by a member of the public over USSD: not verified">Public report (unverified)</span>@endif
                <span class="badge">{{ $incident->label() }}</span>
                <span class="badge {{ $badgeClass }}">{{ $badgeText }}</span>
                <span class="badge {{ $incident->channel === 'web' ? 'channel-web' : '' }}">via {{ $incident->channelLabel() }}</span>
            </div>
            <h3>{{ $incident->placeLabel() }}</h3>
            @if ($incident->ai_priority || $incident->duplicate_of)
                <div class="ai-triage">
                    @if ($incident->ai_priority)<span class="badge ai-{{ $incident->ai_priority }}" title="Suggested by AI from the report">AI: {{ \App\Services\Ai\IncidentTriage::PRIORITIES[$incident->ai_priority] }}</span>@endif
                    @if ($incident->ai_credibility)<span class="badge {{ $incident->ai_credibility === 'doubtful' ? 'warn' : '' }}" title="{{ $incident->ai_reason }}">{{ \App\Services\Ai\IncidentTriage::CREDIBILITY[$incident->ai_credibility] }}</span>@endif
                    @if ($incident->duplicate_of)<span class="badge warn">Possible repeat of <a href="#incident-{{ $incident->duplicate_of }}">{{ $incident->duplicate_of }}</a></span>@endif
                    @if ($incident->ai_summary)<p class="ai-line">{{ $incident->ai_summary }}</p>@endif
                    @if ($incident->ai_action && ! $incident->resolved_at)<p class="ai-line"><b>Suggested:</b> {{ $incident->ai_action }}</p>@endif
                    @if ($incident->ai_credibility && $incident->ai_reason)<p class="ai-line muted small">{{ $incident->ai_reason }}</p>@endif
                </div>
            @endif
            <div class="meta">
                @if ($incident->lga && $incident->ward)
                    <a href="{{ route('monitor.ward', [$incident->lga, $incident->ward]) }}">{{ $incident->lga }} › {{ $incident->ward }}</a> ·
                @endif
                {{ $incident->reference }} · {{ \App\Support\Time::local($incident->reported_at, 'j M, g:i A') }}
            </div>
            @if ($incident->note)<p class="note">{{ $incident->note }}</p>@endif
            @include('partials.media-strip', ['files' => $media[$incident->reference] ?? collect()])
            <div class="meta">Reported by {{ $incident->reporterName() }}@if ($incident->reporterPhone() && auth()->user()->can('view_agents')) · <a class="tel" href="tel:{{ $incident->reporterPhone() }}">Call {{ $incident->reporterPhone() }}</a>@endif</div>

            @if ($incident->acknowledged_at)
                <p class="response">Acknowledged by {{ $incident->acknowledged_by }} at {{ \App\Support\Time::local($incident->acknowledged_at) }}.
                    @if ($incident->resolved_at) Resolved by {{ $incident->resolved_by }} at {{ \App\Support\Time::local($incident->resolved_at) }}@if ($incident->resolution_note): “{{ $incident->resolution_note }}”@endif.@endif
                </p>
            @endif

@can('respond_incidents')
            <div class="actions">
                @if (! $incident->acknowledged_at)
                    <form method="post" action="{{ route('incidents.acknowledge', $incident->reference) }}" data-queue="Acknowledge {{ $incident->reference }}">
                        @csrf
                        <button class="button" type="submit">Acknowledge</button>
                    </form>
                @endif
                @if ($aiOn && ! $incident->resolved_at && (! $incident->ai_priority || $incident->triage_error))
                    <form method="post" action="{{ route('incidents.triage', $incident->reference) }}" data-queue="Triage {{ $incident->reference }}">
                        @csrf
                        <button class="button secondary" type="submit">Triage with AI</button>
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
@endcan
        </li>
    @endforeach
</ul>

<div class="pagination">
    @if ($incidents->previousPageUrl())<a class="button secondary" href="{{ $incidents->previousPageUrl() }}">← Newer</a>@endif
    @if ($incidents->nextPageUrl())<a class="button secondary" href="{{ $incidents->nextPageUrl() }}">Older →</a>@endif
</div>
@endsection
