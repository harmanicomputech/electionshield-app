@extends('layouts.app')

@section('title', 'IReV · '.$unit->name)

@php
    $uploaded = old('irev_status', $official?->irev_status ?? 'uploaded') === 'uploaded';
    $votes = $official?->votesByParty() ?? [];
    $comparison = $official ? (new \App\Services\ResultComparison(\App\Support\Settings::showingRehearsal()))->units($unit->lga)->get($unit->code) : null;
@endphp

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('official') }}">← Enter IReV results</a></div>
    <h1>{{ $unit->name }}</h1>
    <p class="muted">{{ $unit->inecCode() }} · {{ $unit->lga }} › {{ $unit->ward }}@if ($unit->registered_voters) · {{ number_format($unit->registered_voters) }} registered @endif</p>
</div>

@foreach ((array) session('warnings') as $warning)
    <div class="flash bad" role="alert">⚠ {{ $warning }}</div>
@endforeach

@if ($comparison && $official->uploaded())
    <section class="card">
        <h2>Compared with our agent's EC8A</h2>
        @if (! $comparison['pvt'])
            <p class="muted">Our agent hasn't submitted a result for this PU yet.</p>
        @else
            @php($ours = $comparison['pvt']->votesByParty())
            <div class="table-wrap">
                <table class="stack">
                    <thead><tr><th>Party</th><th class="num">PVT ({{ $comparison['pvt']->reference }})</th><th class="num">IReV</th><th class="num">Difference</th></tr></thead>
                    <tbody>
                        @foreach ($official->votesByParty() as $party => $count)
                            <tr>
                                <td class="key">{{ $party }}</td>
                                <td class="num" data-label="PVT">{{ number_format($ours[$party] ?? 0) }}</td>
                                <td class="num" data-label="IReV">{{ number_format($count) }}</td>
                                <td class="num" data-label="Difference">
                                    @php($d = $comparison['diff'][$party])
                                    @if ($d === 0)<span class="muted">0</span>@else<b>{{ $d > 0 ? '+' : '' }}{{ $d }}</b>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p style="margin-top:12px">@if (in_array('discrepancy', $comparison['flags'], true))<span class="badge bad">✗ Differs by up to {{ $comparison['max_diff'] }} votes</span>@else<span class="badge good">✓ Matches within {{ config('election.discrepancy_votes') }} votes</span>@endif</p>
        @endif
    </section>
@endif

<section class="card">
    <h2>{{ $official ? 'IReV result' : 'Enter the IReV result' }}</h2>
    @if ($official)<p class="small muted">Last saved by {{ $official->entered_by }}, {{ \App\Support\Time::local($official->updated_at, 'j M, g:i A') }} ({{ $official->source }}).</p>@endif
    <p class="small muted">Type the figures exactly as on the result sheet in IReV. Our agent's figures are shown only after saving, so they can't sway the entry.</p>
    <form method="post" action="{{ route('official.pu.update', $unit->code) }}">
        @csrf @method('put')
        <label class="inline"><input type="radio" name="irev_status" value="uploaded" @checked($uploaded)> Result sheet is on IReV</label>
        <label class="inline"><input type="radio" name="irev_status" value="not_uploaded" @checked(! $uploaded)> No upload on IReV for this PU</label>

        <div class="filters" style="margin-top:8px">
            @foreach ($parties as $party)
                <div>
                    <label for="v-{{ $party }}">{{ $party }}</label>
                    <input id="v-{{ $party }}" type="text" inputmode="numeric" pattern="[0-9]*" name="votes[{{ $party }}]" value="{{ old("votes.{$party}", $votes[$party] ?? '') }}">
                    @error("votes.{$party}")<div class="field-error">{{ $message }}</div>@enderror
                </div>
            @endforeach
            <div>
                <label for="accredited">Accredited voters</label>
                <input id="accredited" type="text" inputmode="numeric" pattern="[0-9]*" name="accredited_voters" value="{{ old('accredited_voters', $official?->accredited_voters) }}">
            </div>
            <div>
                <label for="rejected">Rejected votes</label>
                <input id="rejected" type="text" inputmode="numeric" pattern="[0-9]*" name="rejected_votes" value="{{ old('rejected_votes', $official?->rejected_votes) }}">
            </div>
        </div>
        <label for="note">Note (optional, e.g. "sheet blurred", "figures altered")</label>
        <textarea id="note" name="note" maxlength="1000">{{ old('note', $official?->note) }}</textarea>
        <p></p>
        <button class="button" type="submit">Save</button>
    </form>
    @if ($official && auth()->user()->isAdmin())
        <form method="post" action="{{ route('official.pu.destroy', $unit->code) }}" onsubmit="return confirm('Delete this IReV entry?')" style="margin-top:12px">
            @csrf @method('delete')
            <button class="button danger" type="submit">Delete entry</button>
        </form>
    @endif
</section>
@endsection
