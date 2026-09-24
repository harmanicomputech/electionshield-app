@extends('layouts.app')

@section('title', 'Moderate · '.$session->title)

@php
    $tabs = ['pending' => 'To moderate', 'approved' => 'Approved', 'answered' => 'Answered', 'rejected' => 'Rejected'];
    $publicLink = route('townhall.show', $session);
@endphp

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('townhall.manage') }}">← Town hall</a></div>
    <h1>{{ $session->title }}</h1>
    <p class="muted">{{ ucfirst($session->phase()) }} · {{ \App\Support\Time::local($session->starts_at, 'l j M, g:i A') }} · public link <a href="{{ $publicLink }}">{{ $publicLink }}</a></p>
</div>

<div class="actions" style="margin-bottom:16px">
    <a class="button" href="{{ route('townhall.present', $session) }}" target="_blank" rel="noopener">Presenter view</a>
    @if (auth()->user()->isAdmin())
        <a class="button secondary" href="{{ route('townhall.edit', $session) }}">Edit session</a>
        <form method="post" action="{{ route('townhall.reminder', $session) }}">@csrf<button class="button secondary" type="submit">SMS reminder to supporters…</button></form>
    @endif
</div>

@if ($onAir)
    <div class="flash ok" role="status"><b>On air:</b> “{{ $onAir->body }}” ({{ $onAir->name ?: 'A voter' }})</div>
@endif

<nav class="tabs" aria-label="Questions">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('townhall.moderate', [$session, 'status' => $key]) }}" class="{{ $status === $key ? 'on' : '' }}">{{ $label }} <b>{{ $counts[$key] ?? 0 }}</b></a>
    @endforeach
</nav>

@if ($questions->isEmpty())<div class="card"><p class="muted">No questions here.</p></div>@endif

<ul class="list two">
    @foreach ($questions as $question)
        <li class="item {{ $question->on_air_at ? 'urgent' : '' }}">
            <p class="note" style="margin-top:0">{{ $question->body }}</p>
            <div class="meta">{{ $question->name ?: 'Anonymous' }}@if ($question->lga), {{ $question->lga }}@endif · {{ \App\Support\Time::local($question->created_at) }}@if ($question->moderated_by) · {{ $question->moderated_by }}@endif</div>
            <div class="actions">
                @foreach (match ($question->status) {
                    'pending' => ['approve' => 'Approve', 'reject' => 'Reject'],
                    'approved' => [($question->on_air_at ? 'off_air' : 'on_air') => ($question->on_air_at ? 'Take off air' : 'Put on air'), 'answered' => 'Mark answered', 'reject' => 'Reject'],
                    'answered' => [($question->on_air_at ? 'off_air' : 'on_air') => ($question->on_air_at ? 'Take off air' : 'Put on air again')],
                    default => ['approve' => 'Approve after all'],
                } as $action => $label)
                    <form method="post" action="{{ route('townhall.act', [$session, $question]) }}" data-queue="{{ $label }}">
                        @csrf
                        <input type="hidden" name="action" value="{{ $action }}">
                        <button class="button {{ in_array($action, ['approve', 'on_air'], true) ? '' : ($action === 'reject' ? 'danger' : 'secondary') }}" type="submit">{{ $label }}</button>
                    </form>
                @endforeach
            </div>
            <div class="queue-state" data-queue-state aria-live="polite"></div>
        </li>
    @endforeach
</ul>
@endsection
