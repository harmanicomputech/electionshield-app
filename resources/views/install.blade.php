@extends('layouts.app')

@section('title', 'Get the app')

@section('head')
    <script src="/vendor/qrcode/qrcode.js?v=1.4.4" defer></script>
@endsection

@php
    $share = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4M5 11v9h14v-9"/></svg>';
    $plus = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="4"/><path d="M12 8v8M8 12h8"/></svg>';
    $dots = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>';
    $appUrl = url('/');
    $chromeIntent = 'intent://'.request()->getHttpHost().'/install#Intent;scheme='.request()->getScheme().';package=com.android.chrome;end';
@endphp

@section('content')
<div class="install-page" data-install-page data-app-url="{{ $appUrl }}">
    <header class="install-hero">
        <img src="/icons/icon-192.png" alt="" width="84" height="84">
        <div>
            <h1>Get the Election Shield app</h1>
            <p class="muted">Put it on your phone's home screen: it opens full screen like any app, keeps working with poor network, and shows alerts.</p>
        </div>
    </header>

    {{-- Already running as the installed app. --}}
    <section class="card install-step" data-step="installed" hidden>
        <h2>✓ You already have the app</h2>
        <p>You're using Election Shield from your home screen. Nothing else to do.</p>
        <a class="button block" href="{{ auth()->check() ? route(auth()->user()->homeRoute()) : route('login') }}">Open Election Shield</a>
    </section>

    {{-- Android (Chrome, Edge, Samsung Internet): one tap. --}}
    <section class="card install-step install-primary" data-step="prompt" hidden>
        <h2>Install in one tap</h2>
        <button class="button block big" type="button" data-install-now>📲 Install the app</button>
        <p class="small muted">Then tap <b>Install</b> on the box that appears. The Election Shield icon is added to your home screen.</p>
        <p class="install-done" data-install-done hidden>✓ Installed. Find <b>Election Shield</b> on your home screen and open it from there.</p>
    </section>

    {{-- Opened inside WhatsApp, Facebook, Instagram…: those can't install apps. --}}
    <section class="card install-step install-warning" data-step="inapp" hidden>
        <h2>First, open this page in your browser</h2>
        <p>This page is open inside another app (like WhatsApp or Facebook), which can't install apps.</p>
        <div data-only="android">
            <a class="button block big" href="{{ $chromeIntent }}">Open in Chrome</a>
            <p class="small muted">Or tap <span class="inline-icon">{!! $dots !!}</span> at the top right and choose <b>Open in Chrome</b> (or <b>Open in browser</b>).</p>
        </div>
        <div data-only="ios">
            <p>Tap <b>•••</b> or the <span class="inline-icon">{!! $share !!}</span> button and choose <b>Open in Safari</b>. Or copy the link and paste it into Safari:</p>
        </div>
        <button class="button secondary block" type="button" data-copy-link="{{ $link }}">Copy the link</button>
    </section>

    {{-- Android without the one-tap box (it was dismissed, or the browser doesn't offer it). --}}
    <section class="card install-step" data-step="android" hidden>
        <h2>Install on Android</h2>
        <ol class="install-steps">
            <li><span class="num">1</span><div>Tap the menu <span class="inline-icon">{!! $dots !!}</span> at the <b>top right</b> of Chrome.</div></li>
            <li><span class="num">2</span><div>Tap <b>Install app</b> (or <b>Add to Home screen</b>).</div></li>
            <li><span class="num">3</span><div>Tap <b>Install</b>. Open <b>Election Shield</b> from your home screen.</div></li>
        </ol>
        <a class="button secondary block" href="{{ $chromeIntent }}" data-only="android">Not using Chrome? Open this page in Chrome</a>
    </section>

    {{-- iPhone and iPad: Apple only allows it from the Share menu. --}}
    <section class="card install-step" data-step="ios" hidden>
        <h2>Install on iPhone</h2>
        <p class="small muted">Apple doesn't allow a one-tap install button, so it takes 3 quick steps in Safari:</p>
        <ol class="install-steps">
            <li><span class="num">1</span><div>Tap the <b>Share</b> button <span class="inline-icon">{!! $share !!}</span> <span data-ios-where>at the bottom of the screen</span>.</div></li>
            <li><span class="num">2</span><div>Scroll down and tap <b>Add to Home Screen</b> <span class="inline-icon">{!! $plus !!}</span>.</div></li>
            <li><span class="num">3</span><div>Tap <b>Add</b> at the top right. Open <b>Election Shield</b> from your home screen.</div></li>
        </ol>
        <div class="ios-pointer" data-ios-pointer aria-hidden="true">Share is down here ↓</div>
    </section>

    {{-- A computer: send it to the phone. --}}
    <section class="card install-step" data-step="desktop" hidden>
        <h2>Open this on your phone</h2>
        <div class="install-qr-row">
            <div class="install-qr" data-qr="{{ $link }}" role="img" aria-label="QR code for {{ $link }}"></div>
            <div>
                <p>Scan this code with the phone's camera, or open this address on the phone:</p>
                <p class="install-link"><a href="{{ $link }}">{{ $link }}</a></p>
                <button class="button secondary" type="button" data-copy-link="{{ $link }}">Copy the link</button>
                <p class="small muted" data-desktop-install hidden>You can also install it on this computer: <button class="button secondary" type="button" data-install-now>Install on this computer</button></p>
            </div>
        </div>
    </section>

    <noscript>
        <section class="card">
            <h2>How to install</h2>
            <p><b>Android:</b> Chrome menu ⋮ → Install app. <b>iPhone:</b> Safari → Share → Add to Home Screen.</p>
        </section>
    </noscript>

    <section class="card install-share">
        <h2>Share with your team</h2>
        <p class="small muted">Send agents and coordinators this link. It opens this page with the right steps for their phone.</p>
        <p class="install-link"><a href="{{ $link }}">{{ $link }}</a></p>
        <div class="row-actions">
            <a class="button" href="https://wa.me/?text={{ rawurlencode('Get the Election Shield app on your phone: '.$link) }}" target="_blank" rel="noopener">Share on WhatsApp</a>
            <button class="button secondary" type="button" data-copy-link="{{ $link }}">Copy the link</button>
            <button class="button secondary" type="button" data-show-qr>Show QR code</button>
        </div>
        <div class="install-qr big" data-qr-share hidden></div>
    </section>

    <p class="small muted install-foot">After installing, sign in once. Agents use their phone number and USSD PIN.</p>
</div>
@endsection
