@extends('layouts.app')

@section('title', 'EC8A '.$photo->result_reference)

@php
    $result = $photo->result;
    $official = $result ? \App\Models\OfficialResult::query()->where('polling_unit_code', $result->polling_unit_code)->first() : null;
@endphp

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('photos') }}">← EC8A photos</a></div>
    <h1>{{ $result?->pollingUnit?->name ?? 'Result '.$photo->result_reference }}</h1>
    <p class="muted">{{ $photo->result_reference }}@if ($result) · {{ $result->pollingUnit?->inecCode() ?? $result->polling_unit_code }} · {{ $result->lga }} › {{ $result->ward }}@endif</p>
</div>

<div class="grid two">
    <section class="card">
        <a href="{{ route('photos.image', $photo) }}" target="_blank" rel="noopener"><img class="sheet" src="{{ route('photos.image', $photo) }}" alt="EC8A result sheet for {{ $photo->result_reference }}" width="{{ $photo->width }}" height="{{ $photo->height }}"></a>
        <p class="small muted" style="margin-top:8px">Tap the photo to open it full size (pinch to zoom). {{ $photo->uploaded_via === 'agent' ? 'Sent by the agent' : 'Uploaded by '.$photo->uploaded_by }}, {{ \App\Support\Time::local($photo->created_at, 'j M, g:i A') }} · {{ number_format($photo->size / 1024) }} KB @if ($photo->width) · {{ $photo->width }}×{{ $photo->height }}@endif</p>
        @if ($photo->note)<p class="small">Note: {{ $photo->note }}</p>@endif
        <details class="small"><summary class="more-btn" style="display:inline-flex">Evidence fingerprint</summary><p>SHA-256 of the file as received: <code>{{ $photo->sha256 }}</code></p></details>
        @if ($others->isNotEmpty())
            <p class="small">Other photos of this sheet: @foreach ($others as $other)<a href="{{ route('photos.show', $other) }}">#{{ $loop->iteration }}</a> @endforeach</p>
        @endif
    </section>

    <div>
        <section class="card">
            <h2>Our agent's figures</h2>
            @if (! $result)
                <p class="muted">The result {{ $photo->result_reference }} hasn't reached this app yet.</p>
            @else
                @php($votes = $result->votesByParty())
                @php($irev = $official?->uploaded() ? $official->votesByParty() : null)
                <div>
                    <table class="compact">
                        <thead><tr><th>Party</th><th class="num">EC8A (USSD)</th>@if ($irev)<th class="num">IReV</th>@endif</tr></thead>
                        <tbody>
                            @foreach ($votes as $party => $count)
                                <tr><td class="key">{{ $party }}</td><td class="num" data-label="EC8A (USSD)">{{ number_format($count) }}</td>@if ($irev)<td class="num" data-label="IReV">{{ number_format($irev[$party] ?? 0) }}</td>@endif</tr>
                            @endforeach
                            <tr><td class="key">Accredited</td><td class="num" data-label="EC8A (USSD)">{{ number_format($result->accredited_voters) }}</td>@if ($irev)<td class="num" data-label="IReV">{{ $official->accredited_voters === null ? '—' : number_format($official->accredited_voters) }}</td>@endif</tr>
                            <tr><td class="key">Rejected</td><td class="num" data-label="EC8A (USSD)">{{ number_format($result->rejected_votes) }}</td>@if ($irev)<td class="num" data-label="IReV">{{ $official->rejected_votes === null ? '—' : number_format($official->rejected_votes) }}</td>@endif</tr>
                        </tbody>
                    </table>
                </div>
                <p class="small muted" style="margin-top:8px">Status: {{ $result->status->label() }}. Submitted by {{ $result->agent_name }} at {{ \App\Support\Time::local($result->submitted_at) }}.</p>
            @endif
        </section>

        <section class="card">
            <h2>Does the sheet match?</h2>
            <p class="small">Currently: <b>{{ $photo->reviewLabel() }}</b>@if ($photo->reviewed_by) ({{ $photo->reviewed_by }}, {{ \App\Support\Time::local($photo->reviewed_at, 'j M, g:i A') }})@endif</p>
            @if ($photo->review_note)<p class="small">“{{ $photo->review_note }}”</p>@endif
            <form method="post" action="{{ route('photos.review', $photo) }}" data-queue="Review {{ $photo->result_reference }}">
                @csrf
                <label class="inline"><input type="radio" name="review_status" value="matches" @checked($photo->review_status === 'matches') required> Matches our agent's figures</label>
                <label class="inline"><input type="radio" name="review_status" value="mismatch" @checked($photo->review_status === 'mismatch')> Does not match</label>
                <label for="review-note">Note (which figures differ, altered entries…)</label>
                <textarea id="review-note" name="review_note" maxlength="1000">{{ $photo->review_note }}</textarea>
                <p></p>
                <button class="button" type="submit">Save</button>
                <div class="queue-state" data-queue-state aria-live="polite"></div>
            </form>
        </section>

        <section class="card">
            <h2>Agent's upload link</h2>
            <p class="small muted">Send this to the agent (SMS or WhatsApp) if they need to send another photo. It works only for {{ $photo->result_reference }} and needs no login.</p>
            <p><code>{{ $uploadLink }}</code></p>
        </section>

        @if (auth()->user()->isAdmin())
            <form method="post" action="{{ route('photos.destroy', $photo) }}" onsubmit="return confirm('Delete this photo? It is evidence.')">
                @csrf @method('delete')
                <button class="button danger" type="submit">Delete photo</button>
            </form>
        @endif
    </div>
</div>
@endsection
