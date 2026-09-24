@extends('layouts.app')

@section('title', 'Enter IReV results')

@section('content')
<div class="page-head">
    <h1>Enter IReV results</h1>
    <p class="muted">Copy each PU's result from INEC's IReV portal (inecelectionresults.ng). {{ number_format($entered) }} entered, {{ number_format($notUploaded) }} marked "no upload", of {{ number_format($units) }} PUs.</p>
</div>

@include('partials.results-tabs')
@include('partials.official-tabs')

<section class="card">
    <h2>Find a polling unit</h2>
    <form method="get" action="{{ route('official') }}" class="filters" style="margin-bottom:0">
        <div class="wide">
            <label for="code">PU code (e.g. EB/212/02633/007 or 21202633007)</label>
            <input id="code" type="text" name="code" inputmode="numeric" autocomplete="off" required>
        </div>
        <div class="wide"><button class="button" type="submit">Open</button></div>
    </form>
</section>

<section class="card">
    <h2>Recently entered</h2>
    @if ($recent->isEmpty())
        <p class="muted">Nothing entered yet.</p>
    @else
        <ul class="list">
            @foreach ($recent as $row)
                <li class="item">
                    <h3><a class="rowlink" href="{{ route('official.pu', $row->polling_unit_code) }}">{{ $row->pollingUnit?->name ?? 'PU '.$row->polling_unit_code }}</a></h3>
                    <div class="meta">{{ $row->lga }} › {{ $row->ward }} · {{ $row->uploaded() ? number_format($row->totalValidVotes()).' valid votes' : 'No upload on IReV' }} · {{ $row->entered_by }}, {{ \App\Support\Time::local($row->updated_at, 'j M, g:i A') }}</div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
@endsection
