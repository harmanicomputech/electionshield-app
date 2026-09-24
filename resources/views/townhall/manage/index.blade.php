@extends('layouts.app')

@section('title', 'Town hall')

@section('content')
<div class="page-head">
    <h1>Town hall</h1>
    <p class="muted">Live sessions with voters. The public page is <a href="{{ route('townhall') }}">{{ route('townhall') }}</a>.</p>
</div>

@if (auth()->user()->isAdmin())<p><a class="button" href="{{ route('townhall.create') }}">New session</a></p>@endif

@if ($sessions->isEmpty())<div class="card"><p class="muted">No sessions yet.</p></div>@endif

<ul class="list">
    @foreach ($sessions as $session)
        <li class="item {{ $session->phase() === 'live' ? 'urgent' : '' }}">
            <div class="badges" style="margin-top:0">
                <span class="badge {{ $session->phase() === 'live' ? 'bad' : '' }}">{{ ucfirst($session->phase()) }}</span>
                @unless ($session->published)<span class="badge warn">Hidden</span>@endunless
                @if ($session->pending_count)<span class="badge warn">{{ $session->pending_count }} to moderate</span>@endif
            </div>
            <h3><a class="rowlink" href="{{ route('townhall.moderate', $session) }}">{{ $session->title }}</a></h3>
            <div class="meta">{{ \App\Support\Time::local($session->starts_at, 'l j M, g:i A') }}@if ($session->host) · {{ $session->host }}@endif</div>
        </li>
    @endforeach
</ul>
@endsection
