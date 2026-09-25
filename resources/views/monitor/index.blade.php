@extends('layouts.app')

@section('title', 'Polling units')

@section('content')
<div class="page-head">
    <h1>Polling unit monitoring</h1>
    <p class="muted">Agent check-in, election materials, results and incidents. Tap an LGA for its wards.</p>
</div>

@include('partials.monitor-stats')

@include('partials.lga-map', ['map' => $map, 'title' => 'By LGA'])

@if ($areas === [])
    <div class="card"><p class="muted">No polling units yet. An admin can import the PU register under System.</p></div>
@else
    <div class="card flush">
        @include('partials.monitor-table', ['label' => 'LGA', 'link' => fn ($name) => route('monitor.lga', $name)])
    </div>
@endif
@endsection
