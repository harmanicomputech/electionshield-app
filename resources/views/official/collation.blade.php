@extends('layouts.app')

@php($form = $level === 'ward' ? 'EC8B' : 'EC8C')
@php($area = $level === 'ward' ? $ward.', '.$lga : $lga)
@php($votes = $collation?->votesByParty() ?? [])

@section('title', $form.' · '.$area)

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('official.collations') }}">← Declared collations</a></div>
    <h1>{{ $form }}: {{ $area }}</h1>
    <p class="muted">{{ $level === 'ward' ? 'Ward collation' : 'LGA collation' }} as declared by INEC.@if ($collation) Last saved by {{ $collation->entered_by }}, {{ \App\Support\Time::local($collation->updated_at, 'j M, g:i A') }}.@endif</p>
</div>

<section class="card">
    <form method="post" action="{{ route('official.collation.update', array_filter([$level, $lga, $level === 'ward' ? $ward : null])) }}">
        @csrf @method('put')
        <div class="filters">
            @foreach ($parties as $party)
                <div>
                    <label for="v-{{ $party }}">{{ $party }}</label>
                    <input id="v-{{ $party }}" type="text" inputmode="numeric" pattern="[0-9]*" name="votes[{{ $party }}]" value="{{ old("votes.{$party}", $votes[$party] ?? '') }}" required>
                    @error("votes.{$party}")<div class="field-error">{{ $message }}</div>@enderror
                </div>
            @endforeach
            <div>
                <label for="accredited">Accredited voters</label>
                <input id="accredited" type="text" inputmode="numeric" pattern="[0-9]*" name="accredited_voters" value="{{ old('accredited_voters', $collation?->accredited_voters) }}">
            </div>
            <div>
                <label for="rejected">Rejected votes</label>
                <input id="rejected" type="text" inputmode="numeric" pattern="[0-9]*" name="rejected_votes" value="{{ old('rejected_votes', $collation?->rejected_votes) }}">
            </div>
        </div>
        <label for="note">Note (optional)</label>
        <textarea id="note" name="note" maxlength="1000">{{ old('note', $collation?->note) }}</textarea>
        <p></p>
        <button class="button" type="submit">Save {{ $form }}</button>
    </form>
</section>
@endsection
