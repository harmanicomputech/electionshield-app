@extends('layouts.app')

@section('title', 'Collation')

@section('content')
<div class="page-head">
    <h1>Collation by LGA</h1>
    <p class="muted">Accepted EC8A results from our agents. Tap an LGA for its wards.</p>
</div>

@if ($areas === [])
    <div class="card"><p class="muted">No LGAs yet. An admin can import the PU register under System.</p></div>
@else
    <div class="card flush">
        @include('partials.area-table', ['areas' => $areas, 'label' => 'LGA', 'total' => $state, 'link' => fn ($name) => route('collation.lga', $name)])
    </div>
@endif
@endsection
