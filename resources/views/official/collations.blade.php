@extends('layouts.app')

@section('title', 'Declared collations')

@section('content')
<div class="page-head">
    <h1>Declared collations</h1>
    <p class="muted">Enter each ward's EC8B and each LGA's EC8C as announced by INEC.</p>
</div>

@include('partials.results-tabs')
@include('partials.official-tabs')

@forelse ($areas as $lga => $wards)
    @php($lgaDone = $lgaDeclared->get($lga))
    <section class="card">
        <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center">
            <h2 style="margin:0">{{ $lga }}</h2>
            <a class="button {{ $lgaDone ? 'secondary' : '' }}" href="{{ route('official.collation', ['lga', $lga]) }}">{{ $lgaDone ? '✓ EC8C entered · edit' : 'Enter EC8C' }}</a>
        </div>
        @php($done = $wardDeclared->get($lga, collect()))
        <p class="small muted" style="margin-top:8px">EC8B: {{ $done->count() }} of {{ $wards->count() }} wards entered</p>
        <details>
            <summary class="more-btn" style="display:inline-flex">Wards</summary>
            <ul class="list" style="margin-top:8px">
                @foreach ($wards as $row)
                    <li><a class="button {{ $done->has($row->ward) ? 'secondary' : '' }} block" href="{{ route('official.collation', ['ward', $lga, $row->ward]) }}">{{ $done->has($row->ward) ? '✓ ' : '' }}{{ $row->ward }}</a></li>
                @endforeach
            </ul>
        </details>
    </section>
@empty
    <div class="card"><p class="muted">Import the PU register first (System → Full import).</p></div>
@endforelse
@endsection
