@extends('layouts.app')

@section('title', $broadcast->exists ? 'Edit broadcast' : 'New broadcast')

@php
    $audience = old() ? ['groups' => old('groups', []), 'lgas' => old('lgas', []), 'wards' => old('wards', [])] : ($broadcast->audience ?? []);
    $channel = old('channel', $broadcast->channel);
@endphp

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('broadcasts') }}">← Broadcasts</a></div>
    <h1>{{ $broadcast->exists ? 'Edit broadcast' : 'New broadcast' }}</h1>
    <p class="muted">Saving makes a draft. You'll see exactly how many people it reaches before anything is sent.</p>
</div>

<form method="post" action="{{ $broadcast->exists ? route('broadcasts.update', $broadcast) : route('broadcasts.store') }}">
    @csrf
    @if ($broadcast->exists) @method('put') @endif

    <section class="card">
        <label for="title">Title (for your records)</label>
        <input id="title" type="text" name="title" value="{{ old('title', $broadcast->title) }}" required maxlength="120">
        @error('title')<div class="field-error">{{ $message }}</div>@enderror

        <p class="small muted" style="margin:12px 0 0">Channel</p>
        <label class="inline"><input type="radio" name="channel" value="sms" @checked($channel === 'sms') data-channel> SMS (Africa's Talking)</label>
        <label class="inline"><input type="radio" name="channel" value="whatsapp" @checked($channel === 'whatsapp') data-channel> WhatsApp (approved template)</label>
    </section>

    <section class="card" data-channel-section="sms" @if ($channel !== 'sms') hidden @endif>
        <h2>SMS message</h2>
        <textarea id="message" name="message" maxlength="918" rows="5" data-sms-counter @disabled($channel !== 'sms')>{{ old('message', $broadcast->message) }}</textarea>
        <p class="small muted" data-sms-count></p>
        @error('message')<div class="field-error">{{ $message }}</div>@enderror
        <p class="small muted">Each SMS part is charged. Emoji and curly quotes switch the message to Unicode, which fits 70 characters per part instead of 160. Promotional SMS don't reach numbers on DND unless your sender ID is approved for it.</p>
    </section>

    <section class="card" data-channel-section="whatsapp" @if ($channel !== 'whatsapp') hidden @endif>
        <h2>WhatsApp template</h2>
        <p class="small muted">Outside a conversation the person started, WhatsApp only delivers templates approved in WhatsApp Manager, and only to people who opted in to WhatsApp.</p>
        <div class="filters">
            <div><label for="template">Template name</label><input id="template" type="text" name="template" value="{{ old('template', $broadcast->template) }}" placeholder="election_update"></div>
            <div><label for="template_language">Language code</label><input id="template_language" type="text" name="template_language" value="{{ old('template_language', $broadcast->template_language ?? 'en') }}"></div>
        </div>
        @error('template')<div class="field-error">{{ $message }}</div>@enderror
        <label for="wa-params">Template values, one per line ({{ '{' }}name} becomes each person's name)</label>
        <textarea id="wa-params" name="message" rows="3" @disabled($channel !== 'whatsapp') data-wa-message>{{ old('message', $broadcast->channel === 'whatsapp' ? $broadcast->message : '') }}</textarea>
    </section>

    <section class="card">
        <h2>Audience</h2>
        @foreach ($groups as $key => $label)
            <label class="inline"><input type="checkbox" name="groups[]" value="{{ $key }}" @checked(in_array($key, $audience['groups'] ?? [], true))> {{ $label }}</label>
        @endforeach
        @error('groups')<div class="field-error">{{ $message }}</div>@enderror

        <details style="margin-top:8px" @if (($audience['lgas'] ?? []) || ($audience['wards'] ?? [])) open @endif>
            <summary class="more-btn" style="display:inline-flex">Limit to LGAs or wards (default: everywhere)</summary>
            @foreach ($areas as $lga => $wards)
                <div style="margin-top:8px">
                    <label class="inline"><input type="checkbox" name="lgas[]" value="{{ $lga }}" @checked(in_array($lga, $audience['lgas'] ?? [], true))> <b>All of {{ $lga }}</b></label>
                    <details><summary class="more-btn small" style="display:inline-flex">…or some wards</summary>
                        @foreach ($wards as $row)
                            <label class="inline"><input type="checkbox" name="wards[]" value="{{ $lga }}|{{ $row->ward }}" @checked(in_array($lga.'|'.$row->ward, $audience['wards'] ?? [], true))> {{ $row->ward }}</label>
                        @endforeach
                    </details>
                </div>
            @endforeach
        </details>
    </section>

    <button class="button" type="submit">Save draft</button>
</form>
@endsection
