@extends('layouts.app')

@section('title', 'EC8A photos')

@php
    $tabs = ['unchecked' => 'Not checked', 'matches' => 'Matches', 'mismatch' => 'Does not match', 'all' => 'All'];
@endphp

@section('content')
<div class="page-head">
    <h1>EC8A photos</h1>
    <p class="muted">Photos of the result sheets, tied to each result's reference. Check each against our agent's figures.</p>
</div>

@include('partials.results-tabs')

<section class="card">
    <h2>Upload a photo</h2>
    @include('partials.photo-form', ['action' => route('photos.store'), 'withReference' => true, 'withNote' => true, 'reference' => $reference])
</section>

<nav class="tabs" aria-label="Review status">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('photos', ['status' => $key]) }}" class="{{ $status === $key ? 'on' : '' }}">{{ $label }} <b>{{ $key === 'all' ? $counts->sum() : ($counts[$key] ?? 0) }}</b></a>
    @endforeach
</nav>

@if ($photos->isEmpty())
    <div class="card"><p class="muted">No photos here.</p></div>
@endif

<ul class="photo-grid">
    @foreach ($photos as $photo)
        <li class="item">
            <a href="{{ route('photos.show', $photo) }}" class="thumb">
                <img src="{{ route('photos.image', [$photo, 'thumb']) }}" alt="EC8A for {{ $photo->result_reference }}" loading="lazy" width="480" height="{{ $photo->width ? (int) round(480 * $photo->height / $photo->width) : 360 }}">
            </a>
            <h3><a class="rowlink" href="{{ route('photos.show', $photo) }}">{{ $photo->result?->pollingUnit?->name ?? $photo->result_reference }}</a></h3>
            <div class="meta">{{ $photo->result_reference }} · {{ $photo->uploaded_via === 'agent' ? 'from the agent' : $photo->uploaded_by }} · {{ \App\Support\Time::local($photo->created_at, 'j M, g:i A') }}</div>
            <div class="badges"><span class="badge {{ ['matches' => 'good', 'mismatch' => 'bad'][$photo->review_status] ?? '' }}">{{ $photo->reviewLabel() }}</span></div>
        </li>
    @endforeach
</ul>

<div class="pagination">
    @if ($photos->previousPageUrl())<a class="button secondary" href="{{ $photos->previousPageUrl() }}">← Newer</a>@endif
    @if ($photos->nextPageUrl())<a class="button secondary" href="{{ $photos->nextPageUrl() }}">Older →</a>@endif
</div>
@endsection
