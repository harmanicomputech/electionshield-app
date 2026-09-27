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
    <p class="muted"><a href="{{ route('evidence', $unit->code) }}">Evidence pack</a> · {{ $unit->inecCode() }} · {{ $unit->lga }} › {{ $unit->ward }}@if ($unit->registered_voters) · {{ number_format($unit->registered_voters) }} registered @endif</p>
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

@php($pvtResult = $comparison['pvt'] ?? \App\Models\Result::query()->counted(\App\Support\Settings::showingRehearsal())->where('polling_unit_code', $unit->code)->first())
@if ($official && $pvtResult)
    @php($shots = \App\Models\Ec8aPhoto::query()->where('result_reference', $pvtResult->reference)->get())
    @if ($shots->isNotEmpty())
        <section class="card">
            <h2>EC8A photos from our agent</h2>
            <ul class="photo-grid">
                @foreach ($shots as $shot)
                    <li class="item"><a class="thumb" href="{{ route('photos.show', $shot) }}"><img src="{{ route('photos.image', [$shot, 'thumb']) }}" alt="EC8A photo" loading="lazy"></a><span class="badge">{{ $shot->reviewLabel() }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif
@endif

<section class="card">
    <h2>{{ $official ? 'IReV result' : 'Enter the IReV result' }}</h2>
    @if ($official)<p class="small muted">Last saved by {{ $official->entered_by }}, {{ \App\Support\Time::local($official->updated_at, 'j M, g:i A') }} ({{ $official->source }}).@if ($official->sheet_path) <a href="{{ route('official.pu.sheet', $unit->code) }}" target="_blank" rel="noopener">View the sheet it was read from</a> (SHA-256 {{ \Illuminate\Support\Str::limit($official->sheet_sha256, 16, '…') }}).@endif</p>@endif
    <p class="small muted">Type the figures exactly as on the result sheet in IReV. Our agent's figures are shown only after saving, so they can't sway the entry.</p>
@if ($official?->needs_check)
    <div class="flash warn" role="status"><b>Fetched from IReV automatically and read by AI: not yet checked.</b> Open <a href="{{ route('official.pu.sheet', $unit->code) }}" target="_blank" rel="noopener">the sheet</a>, compare every figure below, correct any that differ, and press Save to mark it checked.</div>
@endif
@can('manage_official_results')
    <details class="ai-read" @if (! $official && ! $reading) open @endif>
        <summary><b>✨ Read the sheet with AI</b> <span class="muted small">Upload the IReV sheet (or paste its link) and the figures are filled in below for you to check.</span></summary>
        @if ($reader)
            <form method="post" action="{{ route('official.pu.read', $unit->code) }}" enctype="multipart/form-data" data-busy="Reading the sheet… (about 10–30 seconds)">
                @csrf
                <label for="sheet_file">Result sheet (JPEG, PNG or PDF from IReV)</label>
                <input id="sheet_file" type="file" name="sheet_file" accept="image/*,application/pdf">
                <label for="sheet_url">or the sheet's link on IReV</label>
                <input id="sheet_url" type="url" name="sheet_url" placeholder="https://…" value="{{ old('sheet_url') }}">
                @error('sheet_file')<div class="field-error">{{ $message }}</div>@enderror
                @error('sheet_url')<div class="field-error">{{ $message }}</div>@enderror
                <p></p>
                <button class="button secondary" type="submit">Read the figures</button>
            </form>
        @else
            <p class="small">To switch this on, add <code>ANTHROPIC_API_KEY</code> (from console.anthropic.com) to <code>.env</code>. Reading a sheet costs about 1–3 US cents of API credit.</p>
        @endif
    </details>

    @if ($reading)
        <div class="flash warn" role="status">
            <b>AI read these figures. Check each one against the sheet before you press Save.</b>
            @foreach ($reading['warnings'] as $warning)<br>⚠ {{ $warning }}@endforeach
        </div>
    @endif

    <form method="post" action="{{ route('official.pu.update', $unit->code) }}">
        @csrf @method('put')
        @if (old('sheet'))<input type="hidden" name="sheet" value="{{ old('sheet') }}">@endif
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
@endcan
    @if ($official && auth()->user()->can('delete_evidence'))
        <form method="post" action="{{ route('official.pu.destroy', $unit->code) }}" onsubmit="return confirm('Delete this IReV entry?')" style="margin-top:12px">
            @csrf @method('delete')
            <button class="button danger" type="submit">Delete entry</button>
        </form>
    @endif
</section>
@endsection
