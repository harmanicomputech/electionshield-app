@extends('layouts.app')

@section('title', $lga.' · Official vs PVT')

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('compare') }}">← All LGAs</a></div>
    <h1>{{ $lga }}: official vs PVT</h1>
    <p class="muted">
        @if ($declared)
            EC8C entered by {{ $declared->entered_by }}@if ($lgaRow && $lgaRow['flagged']) · <b>differs from the PVT by {{ $lgaRow['max_share_diff'] }} points</b>@endif.
            <a href="{{ route('official.collation', ['lga', $lga]) }}">Edit EC8C</a>
        @else
            <a href="{{ route('official.collation', ['lga', $lga]) }}">Enter the declared EC8C for {{ $lga }} →</a>
        @endif
    </p>
</div>

@include('partials.compare-summary')

<section class="card flush">
    <h2>Ward collations (EC8B)</h2>
    @include('partials.collation-compare', [
        'label' => 'Ward', 'form' => 'EC8B',
        'link' => fn ($name) => route('official.collation', ['ward', $lga, $name]),
        'enter' => fn ($name) => route('official.collation', ['ward', $lga, $name]),
    ])
</section>

<section class="card">
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
        <h2 style="margin:0">Polling units to check</h2>
        @if (auth()->user()->isAdmin())
            <a class="button secondary" href="{{ route('compare.export', ['lga' => $lga]) }}">Export flagged (CSV)</a>
        @endif
    </div>
    @include('partials.flagged-units')
</section>
@endsection
