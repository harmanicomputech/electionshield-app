@extends('layouts.app')

@section('title', 'Result checks')

@php
    $n = fn ($value) => number_format((int) $value);
@endphp

@section('content')
<div class="page-head">
    <h1>Result checks</h1>
    <p class="muted">Agents' results that need a second look: votes that don't add up, turnout or a winner's share that is hard to believe, figures out of line with the rest of the ward or with IReV, and EC8A photos whose figures differ from what the agent sent{{ $aiOn ? '' : ' (photo reading needs AI, which is off)' }}. A flag is a reason to check, not proof: call the agent, look at the photo, then mark it reviewed.</p>
</div>

<nav class="tabs" aria-label="Show">
    <a href="{{ route('result-checks') }}" class="{{ $reviewed ? '' : 'on' }}">To review <b>{{ $counts['open'] }}</b></a>
    <a href="{{ route('result-checks', ['show' => 'reviewed']) }}" class="{{ $reviewed ? 'on' : '' }}">Reviewed <b>{{ $counts['reviewed'] }}</b></a>
</nav>

@if ($aiOn)
    <p class="small muted">EC8A photos read by AI so far: {{ $n($photosRead) }}@if ($photosWaiting) · {{ $n($photosWaiting) }} waiting (read in the background, a few a minute)@endif.</p>
@endif

@if ($rows->isEmpty())
    <div class="card"><p class="muted">{{ $reviewed ? 'Nothing reviewed yet.' : 'No results need a second look.' }}</p></div>
@endif

<ul class="list two">
    @foreach ($rows as $row)
        @php
            $result = $row['result'];
            $check = $row['check'];
            $photo = $photos[$result->reference] ?? null;
        @endphp
        <li class="item {{ $row['level'] === 'serious' ? 'urgent' : '' }}" id="check-{{ $result->reference }}">
            <div class="badges" style="margin-top:0">
                <span class="badge {{ $row['level'] === 'serious' ? 'bad' : 'warn' }}">{{ $row['level'] === 'serious' ? '⚠ Serious' : 'Check' }}</span>
                @if ($check?->photo_status === 'matches')<span class="badge good">✓ Photo matches</span>@endif
                @if ($check?->reviewed_at)<span class="badge good">✓ Reviewed</span>@endif
            </div>
            <h3>{{ $result->pollingUnit?->name ?? 'PU '.$result->polling_unit_code }}</h3>
            <div class="meta">
                {{ $result->pollingUnit?->inecCode() ?? $result->polling_unit_code }} ·
                @if ($result->lga && $result->ward)<a href="{{ route('collation.ward', [$result->lga, $result->ward]) }}">{{ $result->lga }} › {{ $result->ward }}</a> ·@endif
                {{ $result->reference }} · {{ \App\Support\Time::local($result->submitted_at, 'j M, g:i A') }}
            </div>
            <ul class="check-flags">
                @foreach ($row['flags'] as $flag)
                    <li class="{{ $flag['level'] }}">{{ $flag['text'] }}</li>
                @endforeach
            </ul>
            <div class="meta">
                Accredited {{ $n($result->accredited_voters) }} · Valid {{ $n($result->total_valid_votes) }} · Rejected {{ $n($result->rejected_votes) }} ·
                {{ collect($result->votesByParty())->map(fn ($v, $p) => "{$p} ".number_format($v))->implode(' · ') }}
            </div>
            @if ($photo)
                <div class="check-photo">
                    <a href="{{ route('photos.show', $photo) }}"><img src="{{ route('photos.image', [$photo, 'thumb']) }}" alt="EC8A photo for {{ $result->reference }}" loading="lazy"></a>
                    <div class="small">
                        @if ($check?->photo_checked_at)
                            AI read the photo {{ \App\Support\Time::local($check->photo_checked_at, 'g:i A') }}:
                            {{ match ($check->photo_status) { 'matches' => 'the figures match.', 'mismatch' => 'the figures differ.', default => 'it could not be read.' } }}
                        @elseif ($check?->photo_error)
                            <span class="muted">The AI could not read it ({{ $check->photo_error }}).</span>
                        @elseif ($aiOn)
                            <span class="muted">Waiting to be read by AI.</span>
                        @endif
                        @can('review_media')
                            @if ($aiOn)
                                <form method="post" action="{{ route('result-checks.photo', $result->reference) }}" style="margin-top:6px">
                                    @csrf
                                    <button class="button secondary" type="submit">{{ $check?->photo_checked_at ? 'Read again' : 'Read now' }}</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                </div>
            @else
                <p class="small muted">No EC8A photo yet.</p>
            @endif
            <div class="meta">Sent by {{ $result->agent_name ?: 'an agent' }}@if ($result->agent_phone && auth()->user()->can('view_agents')) · <a class="tel" href="tel:{{ $result->agent_phone }}">Call {{ $result->agent_phone }}</a>@endif</div>
            @if ($check?->reviewed_at)
                <p class="response">Reviewed by {{ $check->reviewed_by }} on {{ \App\Support\Time::local($check->reviewed_at, 'j M, g:i A') }}@if ($check->review_note): “{{ $check->review_note }}”@endif</p>
            @endif
            @can('acknowledge_results')
                <div class="actions">
                    @if ($check?->reviewed_at)
                        <form method="post" action="{{ route('result-checks.review', $result->reference) }}" data-queue="Reopen {{ $result->reference }}">
                            @csrf
                            <input type="hidden" name="reviewed" value="0">
                            <button class="button secondary" type="submit">Back to the list</button>
                        </form>
                    @else
                        <details>
                            <summary class="button">Mark reviewed…</summary>
                            <form method="post" action="{{ route('result-checks.review', $result->reference) }}" data-queue="Reviewed {{ $result->reference }}">
                                @csrf
                                <input type="hidden" name="reviewed" value="1">
                                <label for="note-{{ $result->reference }}">What you found (optional)</label>
                                <textarea id="note-{{ $result->reference }}" name="review_note" maxlength="500" placeholder="e.g. Called the agent: figures confirmed against the sheet"></textarea>
                                <p></p>
                                <button class="button" type="submit">Save</button>
                            </form>
                        </details>
                    @endif
                </div>
                <div class="queue-state" data-queue-state aria-live="polite"></div>
            @endcan
        </li>
    @endforeach
</ul>
@endsection
