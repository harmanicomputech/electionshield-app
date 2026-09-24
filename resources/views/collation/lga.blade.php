@extends('layouts.app')

@section('title', $lga)

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('collation') }}">← All LGAs</a></div>
    <h1>{{ $lga }}</h1>
    <p class="muted">Collation by ward. Tap a ward for its polling units.</p>
</div>

<div class="card flush">
    @include('partials.area-table', ['areas' => $areas, 'label' => 'Ward', 'link' => fn ($name) => route('collation.ward', [$lga, $name])])
</div>
@endsection
