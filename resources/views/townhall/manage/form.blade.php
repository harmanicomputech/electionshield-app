@extends('layouts.app')

@section('title', $session->exists ? 'Edit session' : 'New session')

@php($local = fn ($time) => $time ? \App\Support\Time::local($time, 'Y-m-d\TH:i') : '')

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('townhall.manage') }}">← Town hall</a></div>
    <h1>{{ $session->exists ? 'Edit session' : 'New session' }}</h1>
</div>

<form class="card" method="post" action="{{ $session->exists ? route('townhall.update', $session) : route('townhall.store') }}">
    @csrf
    @if ($session->exists) @method('put') @endif
    <label for="t-title">Title</label>
    <input id="t-title" type="text" name="title" value="{{ old('title', $session->title) }}" required maxlength="150">
    @error('title')<div class="field-error">{{ $message }}</div>@enderror
    <label for="t-host">Host (e.g. the candidate)</label>
    <input id="t-host" type="text" name="host" value="{{ old('host', $session->host) }}" maxlength="150">
    <div class="filters" style="margin-top:8px">
        <div class="wide"><label for="t-start">Starts (Nigeria time)</label><input id="t-start" type="datetime-local" name="starts_at" value="{{ old('starts_at', $local($session->starts_at)) }}" required style="width:100%;min-height:44px"></div>
        <div class="wide"><label for="t-end">Ends (optional; default 2 hours)</label><input id="t-end" type="datetime-local" name="ends_at" value="{{ old('ends_at', $local($session->ends_at)) }}" style="width:100%;min-height:44px"></div>
    </div>
    @error('ends_at')<div class="field-error">{{ $message }}</div>@enderror
    <label for="t-stream">Live stream link (YouTube or Facebook)</label>
    <input id="t-stream" type="text" name="stream_url" value="{{ old('stream_url', $session->stream_url) }}" placeholder="https://www.youtube.com/live/…">
    @error('stream_url')<div class="field-error">{{ $message }}</div>@enderror
    <label for="t-rec">Recording link (shown after the session)</label>
    <input id="t-rec" type="text" name="recording_url" value="{{ old('recording_url', $session->recording_url) }}">
    @error('recording_url')<div class="field-error">{{ $message }}</div>@enderror
    <label for="t-desc">Description</label>
    <textarea id="t-desc" name="description" maxlength="2000" rows="4">{{ old('description', $session->description) }}</textarea>
    <label class="inline"><input type="checkbox" name="questions_open" value="1" @checked(old('questions_open', $session->questions_open))> Accept questions</label>
    <label class="inline"><input type="checkbox" name="published" value="1" @checked(old('published', $session->published))> Show on the public town hall page</label>
    <button class="button" type="submit">Save</button>
</form>
@endsection
