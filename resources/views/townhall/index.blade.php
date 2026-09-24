@extends('layouts.app')

@section('title', 'Town hall')

@php($phaseBadge = ['live' => ['bad', '● Live now'], 'upcoming' => ['', 'Upcoming'], 'ended' => ['', 'Ended']])

@section('content')
<div class="page-head">
    <h1>Digital town hall</h1>
    <p class="muted">Live sessions for the {{ config('election.name') }}. Watch, and send your questions.</p>
</div>

@if ($current->isEmpty())
    <div class="card"><p class="muted">No sessions are scheduled right now. Check back soon.</p></div>
@endif

<ul class="list">
    @foreach ($current as $session)
        @php([$class, $label] = $phaseBadge[$session->phase()])
        <li class="item {{ $session->phase() === 'live' ? 'urgent' : '' }}">
            <div class="badges" style="margin-top:0"><span class="badge {{ $class }}">{{ $label }}</span></div>
            <h3><a class="rowlink" href="{{ route('townhall.show', $session) }}">{{ $session->title }}</a></h3>
            <div class="meta">{{ \App\Support\Time::local($session->starts_at, 'l j F, g:i A') }}@if ($session->host) · with {{ $session->host }}@endif</div>
            @if ($session->description)<p class="note">{{ \Illuminate\Support\Str::limit($session->description, 180) }}</p>@endif
        </li>
    @endforeach
</ul>

@if ($past->isNotEmpty())
    <h2 style="margin-top:24px">Past sessions</h2>
    <ul class="list">
        @foreach ($past as $session)
            <li class="item">
                <h3><a class="rowlink" href="{{ route('townhall.show', $session) }}">{{ $session->title }}</a></h3>
                <div class="meta">{{ \App\Support\Time::local($session->starts_at, 'j F Y') }}{{ $session->recording_url ? ' · recording available' : '' }}</div>
            </li>
        @endforeach
    </ul>
@endif
@endsection
