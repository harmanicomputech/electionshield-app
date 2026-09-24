@extends('layouts.app')

@section('title', 'Broadcasts')

@php($badge = ['draft' => '', 'scheduled' => 'warn', 'sending' => 'warn', 'sent' => 'good', 'cancelled' => 'bad'])

@section('content')
<div class="page-head">
    <h1>Broadcasts</h1>
    <p class="muted">Election updates and reminders by SMS or WhatsApp, to supporters, agents and coordinators.</p>
</div>

@include('partials.messaging-tabs')

<p><a class="button" href="{{ route('broadcasts.create') }}">New broadcast</a></p>

@if ($broadcasts->isEmpty())
    <div class="card"><p class="muted">No broadcasts yet.</p></div>
@endif

<ul class="list">
    @foreach ($broadcasts as $broadcast)
        <li class="item">
            <div class="badges" style="margin-top:0">
                <span class="badge {{ $badge[$broadcast->status] ?? '' }}">{{ ucfirst($broadcast->status) }}</span>
                <span class="badge">{{ $broadcast->channel === 'sms' ? 'SMS' : 'WhatsApp' }}</span>
            </div>
            <h3><a class="rowlink" href="{{ route('broadcasts.show', $broadcast) }}">{{ $broadcast->title }}</a></h3>
            <div class="meta">
                @if ($broadcast->status === 'scheduled') For {{ \App\Support\Time::local($broadcast->scheduled_at, 'j M, g:i A') }} ·
                @elseif ($broadcast->started_at) Sent {{ \App\Support\Time::local($broadcast->started_at, 'j M, g:i A') }} ·
                @endif
                by {{ $broadcast->sent_by ?? $broadcast->created_by }}
            </div>
        </li>
    @endforeach
</ul>

<div class="pagination">
    @if ($broadcasts->previousPageUrl())<a class="button secondary" href="{{ $broadcasts->previousPageUrl() }}">← Newer</a>@endif
    @if ($broadcasts->nextPageUrl())<a class="button secondary" href="{{ $broadcasts->nextPageUrl() }}">Older →</a>@endif
</div>
@endsection
