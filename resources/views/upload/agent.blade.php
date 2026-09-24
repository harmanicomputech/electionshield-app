@extends('layouts.app')

@section('title', 'Send the EC8A photo')

@section('content')
<div class="auth">
    <div class="card">
        <h1>Send the EC8A photo</h1>
        <p class="muted">Result {{ $reference }}@if ($unit) · {{ $unit->name }}@endif</p>
        @if ($count >= $max)
            <p class="flash ok">We have {{ $count }} photos of this sheet. Thank you.</p>
        @else
            @if ($count)<p class="small">{{ $count }} photo{{ $count > 1 ? 's' : '' }} received so far. You can send another if the first was blurred.</p>@endif
            @include('partials.photo-form', ['action' => route('upload.agent.store', [$reference, $token]), 'label' => 'EC8A '.$reference])
            <p class="small muted" style="margin-top:12px">No signal? Press Upload anyway. The photo is kept on this phone and sent when the network returns; keep this page or the app open.</p>
        @endif
    </div>
</div>
@endsection
