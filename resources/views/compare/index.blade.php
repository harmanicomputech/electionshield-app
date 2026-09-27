@extends('layouts.app')

@section('title', 'Official vs PVT')

@section('content')
<div class="page-head">
    <h1>Official results vs PVT</h1>
    <p class="muted">INEC's IReV results and declared collations against our agents' EC8A figures.</p>
</div>

@include('partials.results-tabs')
@include('partials.official-tabs')
@include('partials.compare-summary')
@if ($unchecked = \App\Models\OfficialResult::query()->where('needs_check', true)->count())
    <div class="flash warn small">{{ number_format($unchecked) }} IReV {{ $unchecked === 1 ? 'result was' : 'results were' }} read by AI and {{ $unchecked === 1 ? 'is' : 'are' }} not yet checked by a person: treat a difference there as provisional until checked. <a href="{{ route('official', ['unchecked' => 1]) }}">Check them</a></div>
@endif

<section class="card flush">
    <h2>LGA collations (EC8C)</h2>
    @include('partials.collation-compare', [
        'label' => 'LGA', 'form' => 'EC8C',
        'link' => fn ($name) => route('compare.lga', $name),
        'enter' => fn ($name) => route('official.collation', ['lga', $name]),
    ])
</section>

<section class="card">
    <div class="page-head" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
        <h2 style="margin:0">Polling units to check</h2>
        @if (auth()->user()->can('export_data'))
            <div class="actions">
                <a class="button secondary" href="{{ route('compare.export') }}">Export flagged (CSV)</a>
                <a class="button secondary" href="{{ route('compare.export', ['all' => 1]) }}">Export all</a>
            </div>
        @endif
    </div>
    @include('partials.flagged-units')
</section>
@endsection
