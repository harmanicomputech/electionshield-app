@extends('layouts.app')

@section('title', 'Corrections')

@section('content')
@php
    $parties = \App\Services\Collation::parties();
@endphp
<div class="page-head">
    <h1>Corrections</h1>
    <p class="muted">Agents who made a mistake send a corrected result by USSD. It counts only once approved here, and then replaces the original.</p>
</div>

@unless ($connected)
    <div class="flash bad">The USSD service isn't connected (USSD_API_URL / USSD_API_TOKEN), so decisions can't be sent. Review in the USSD console instead.</div>
@endunless

@if ($pending->isEmpty())
    <div class="card"><p class="muted">No corrections waiting for review.</p></div>
@endif

<ul class="list two">
    @foreach ($pending as $correction)
        @php
            $original = $originals->get($correction->corrects_reference);
            $new = $correction->votesByParty();
            $old = $original?->votesByParty() ?? [];
            $rows = collect($parties)->mapWithKeys(fn ($p) => [$p => [$old[$p] ?? null, $new[$p] ?? 0]])
                ->put('Accredited', [$original?->accredited_voters, $correction->accredited_voters])
                ->put('Rejected', [$original?->rejected_votes, $correction->rejected_votes]);
        @endphp
        <li class="item">
            <div class="badges" style="margin-top:0"><span class="badge warn">Waiting for review</span></div>
            <h3>{{ $correction->pollingUnit?->name ?? 'PU '.$correction->polling_unit_code }}</h3>
            <div class="meta">{{ $correction->reference }} corrects {{ $correction->corrects_reference }} · {{ $correction->lga }} › {{ $correction->ward }} · {{ $correction->agent_name }}, {{ \App\Support\Time::local($correction->submitted_at, 'j M, g:i A') }}</div>

            <table class="compact" style="margin-top:8px">
                <thead><tr><th></th><th class="num">Original</th><th class="num">Correction</th><th class="num">Change</th></tr></thead>
                <tbody>
                    @foreach ($rows as $label => [$before, $after])
                        @php($change = $before === null ? null : $after - $before)
                        <tr>
                            <td>{{ $label }}</td>
                            <td class="num">{{ $before === null ? '—' : number_format($before) }}</td>
                            <td class="num">{{ number_format($after) }}</td>
                            <td class="num">@if ($change)<b>{{ $change > 0 ? '+' : '' }}{{ number_format($change) }}</b>@else<span class="muted">0</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="actions" style="margin-top:10px">
                <form method="post" action="{{ route('corrections.approve', $correction->reference) }}" data-queue="Approve {{ $correction->reference }}">
                    @csrf
                    <button class="button" type="submit" @disabled(! $connected)>Approve</button>
                </form>
                <details>
                    <summary class="button danger">Reject…</summary>
                    <form method="post" action="{{ route('corrections.reject', $correction->reference) }}" data-queue="Reject {{ $correction->reference }}">
                        @csrf
                        <label for="note-{{ $correction->reference }}">Why? (sent to the audit log)</label>
                        <input id="note-{{ $correction->reference }}" type="text" name="note" maxlength="255" required>
                        <p></p>
                        <button class="button danger" type="submit" @disabled(! $connected)>Reject correction</button>
                    </form>
                </details>
            </div>
            <div class="queue-state" data-queue-state aria-live="polite"></div>
            @if ($photo = \App\Models\Ec8aPhoto::query()->whereIn('result_reference', [$correction->reference, $correction->corrects_reference])->first())
                <p class="small" style="margin-top:6px"><a href="{{ route('photos.show', $photo) }}">📷 Check the EC8A photo</a></p>
            @endif
        </li>
    @endforeach
</ul>

@if ($recent->isNotEmpty())
    <section class="card" style="margin-top:16px">
        <h2>Recently decided</h2>
        <ul class="small">
            @foreach ($recent as $row)
                <li>{{ $row->reference }} ({{ $row->pollingUnit?->name ?? $row->polling_unit_code }}): <b>{{ in_array($row->status->value, ['accepted', 'superseded'], true) ? 'approved' : 'rejected' }}</b> by {{ $row->reviewed_by ?? 'someone' }}, {{ \App\Support\Time::local($row->reviewed_at, 'j M, g:i A') }}@if ($row->review_note): “{{ $row->review_note }}”@endif</li>
            @endforeach
        </ul>
    </section>
@endif
@endsection
