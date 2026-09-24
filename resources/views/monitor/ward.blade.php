@extends('layouts.app')

@section('title', $ward.' · PUs')

@php
    $materialBadge = [
        'arrived' => ['good', '✓ Materials arrived'],
        'incomplete' => ['warn', '! Materials incomplete'],
        'not_arrived' => ['bad', '✗ Materials not arrived'],
    ];
@endphp

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('monitor') }}">All LGAs</a> › <a href="{{ route('monitor.lga', $lga) }}">{{ $lga }}</a></div>
    <h1>{{ $ward }}</h1>
    <p class="muted">{{ $count }} polling units · {{ $attention }} need attention</p>
</div>

<nav class="tabs" aria-label="Filter">
    <a href="{{ route('monitor.ward', [$lga, $ward]) }}" class="{{ $problems ? '' : 'on' }}">All <b>{{ $count }}</b></a>
    <a href="{{ route('monitor.ward', [$lga, $ward, 'problems' => 1]) }}" class="{{ $problems ? 'on' : '' }}">Need attention <b>{{ $attention }}</b></a>
</nav>

@if ($units->isEmpty())
    <div class="card"><p class="muted">Nothing needs attention here.</p></div>
@endif

<ul class="list two">
    @foreach ($units as $unit)
        <li class="item {{ $unit->urgentIncidents ? 'urgent' : '' }}">
            <h3>{{ $unit->name() }}</h3>
            <div class="meta">{{ $unit->unit?->inecCode() ?? $unit->code }}@if ($unit->unit?->registered_voters) · {{ number_format($unit->unit->registered_voters) }} registered @endif</div>
            <div class="badges">
                @if ($unit->checkedInAt)
                    <span class="badge good">✓ Checked in {{ \App\Support\Time::local($unit->checkedInAt) }}</span>
                @else
                    <span class="badge warn">No check-in</span>
                @endif

                @if ($unit->materials)
                    @php([$class, $text] = $materialBadge[$unit->materials->status] ?? ['', $unit->materials->status_label ?? $unit->materials->status])
                    <span class="badge {{ $class }}">{{ $text }} · {{ \App\Support\Time::local($unit->materials->reported_at) }}</span>
                @else
                    <span class="badge">No materials report</span>
                @endif

                @if ($unit->result)
                    <span class="badge good">✓ Result {{ $unit->result->reference }}</span>
                @else
                    <span class="badge">No result yet</span>
                @endif

                @if ($unit->openIncidents)
                    <a class="badge {{ $unit->urgentIncidents ? 'bad' : 'warn' }}" href="{{ route('incidents', ['lga' => $lga]) }}">{{ $unit->openIncidents }} open incident{{ $unit->openIncidents > 1 ? 's' : '' }}@if ($unit->urgentIncidents) ({{ $unit->urgentIncidents }} urgent)@endif</a>
                @endif
            </div>
        </li>
    @endforeach
</ul>
@endsection
