@extends('layouts.app')

@section('title', $session->title)

@php($phase = $session->phase())

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('townhall') }}">← Town hall</a></div>
    <h1>{{ $session->title }}</h1>
    <p class="muted">
        @if ($phase === 'live')<span class="badge bad">● Live now</span>@elseif ($phase === 'upcoming')<span class="badge">Starts {{ \App\Support\Time::local($session->starts_at, 'l j F, g:i A') }}</span>@else<span class="badge">Ended</span>@endif
        @if ($session->host) with {{ $session->host }}@endif
    </p>
</div>

<div class="grid two">
    <div>
        @if ($embed)
            @include('partials.townhall-player', ['title' => $session->title])
        @else
            <div class="card"><p class="muted">{{ $phase === 'upcoming' ? 'The live stream will appear here when the session starts.' : 'No video for this session.' }}</p></div>
        @endif
        @if ($session->description)<div class="card" style="margin-top:16px"><p style="white-space:pre-line;margin:0">{{ $session->description }}</p></div>@endif
    </div>

    <div>
        <section class="card">
            <h2>Ask a question</h2>
            @if ($session->acceptsQuestions())
                <form method="post" action="{{ route('townhall.ask', $session) }}">
                    @csrf
                    <div style="position:absolute;left:-10000px" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                    <label for="q-body">Your question</label>
                    <textarea id="q-body" name="body" maxlength="280" required>{{ old('body') }}</textarea>
                    @error('body')<div class="field-error">{{ $message }}</div>@enderror
                    <div class="filters" style="margin-top:8px">
                        <div><label for="q-name">Your name (optional)</label><input id="q-name" type="text" name="name" maxlength="80" value="{{ old('name') }}" autocomplete="given-name"></div>
                        <div><label for="q-lga">Your LGA (optional)</label><select id="q-lga" name="lga"><option value="">—</option>@foreach ($lgas as $lga)<option @selected(old('lga') === $lga)>{{ $lga }}</option>@endforeach</select></div>
                    </div>
                    <button class="button" type="submit">Send question</button>
                    <p class="small muted" style="margin-top:8px">Questions are read by a moderator before they appear. Please be respectful.</p>
                </form>
            @else
                <p class="muted">Questions are closed for this session.</p>
            @endif
        </section>

        <section class="card">
            <h2>Questions</h2>
            <ul class="list" data-townhall-feed="{{ route('townhall.questions', $session) }}">
                @forelse ($questions as $question)
                    <li class="item {{ $question->on_air_at ? 'urgent' : '' }}">
                        @if ($question->on_air_at)<span class="badge bad">● Being answered now</span>@elseif ($question->status === 'answered')<span class="badge good">✓ Answered</span>@endif
                        <p class="note" style="margin:6px 0">{{ $question->body }}</p>
                        <div class="meta">{{ $question->name ?: 'A voter' }}@if ($question->lga), {{ $question->lga }}@endif</div>
                    </li>
                @empty
                    <li class="muted small" data-empty>No questions yet. Be the first to ask.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>

<p class="small muted"><a href="{{ route('join') }}">Get election updates by SMS or WhatsApp →</a></p>
@endsection
