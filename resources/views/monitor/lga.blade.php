@extends('layouts.app')

@section('title', $lga.' · PUs')

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('monitor') }}">← All LGAs</a></div>
    <h1>{{ $lga }}</h1>
    <p class="muted">Polling unit status by ward. <a href="{{ route('incidents', ['lga' => $lga]) }}">Incidents in {{ $lga }} →</a></p>
</div>

@include('partials.monitor-stats')

<div class="card flush">
    @include('partials.monitor-table', ['label' => 'Ward', 'link' => fn ($name) => route('monitor.ward', [$lga, $name])])
</div>
@endsection
