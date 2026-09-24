@extends('layouts.app')

@section('title', 'Get election updates')

@section('content')
<div class="auth">
    <div class="card">
        <h1>Get election updates</h1>
        <p class="muted">News and reminders for the {{ config('election.name') }}, {{ \Illuminate\Support\Carbon::parse(config('election.date'))->format('j F Y') }}.</p>
        <form method="post" action="{{ route('join.store') }}">
            @csrf
            <div style="position:absolute;left:-10000px" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
            <label for="j-name">Your name</label>
            <input id="j-name" type="text" name="name" value="{{ old('name') }}" required autocomplete="name">
            @error('name')<div class="field-error">{{ $message }}</div>@enderror
            <label for="j-phone">Phone number</label>
            <input id="j-phone" type="text" name="phone" value="{{ old('phone') }}" inputmode="tel" required autocomplete="tel" placeholder="0803 123 4567">
            @error('phone')<div class="field-error">{{ $message }}</div>@enderror
            <label for="j-lga">Your LGA (optional)</label>
            <select id="j-lga" name="lga"><option value="">Choose…</option>@foreach ($lgas as $lga)<option @selected(old('lga') === $lga)>{{ $lga }}</option>@endforeach</select>
            <p class="small muted" style="margin:12px 0 0">Send me updates by</p>
            <label class="inline"><input type="checkbox" name="sms" value="1" @checked(old('sms', true))> SMS</label>
            <label class="inline"><input type="checkbox" name="whatsapp" value="1" @checked(old('whatsapp'))> WhatsApp</label>
            @error('sms')<div class="field-error">{{ $message }}</div>@enderror
            <label class="inline"><input type="checkbox" name="consent" value="1" required> I agree to receive election messages. I can reply STOP at any time to stop them.</label>
            @error('consent')<div class="field-error">{{ $message }}</div>@enderror
            <button class="button block" type="submit">Sign up</button>
        </form>
    </div>
</div>
@endsection
