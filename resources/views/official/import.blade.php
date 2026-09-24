@extends('layouts.app')

@section('title', 'Import official results')

@php($partyColumns = implode(',', $parties))

@section('content')
<div class="page-head">
    <h1>Import official results</h1>
    <p class="muted">Upload a CSV. Rows are matched by PU code (or level, LGA and ward) and overwrite what's there, so a corrected file can be imported again.</p>
</div>

@include('partials.results-tabs')
@include('partials.official-tabs')

@if (session('import_errors'))
    <div class="card">
        <h2>Skipped rows</h2>
        <ul class="small">@foreach (session('import_errors') as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<section class="card">
    <form method="post" action="{{ route('official.import.store') }}" enctype="multipart/form-data">
        @csrf
        <label class="inline"><input type="radio" name="type" value="irev" checked> IReV results per PU</label>
        <label class="inline"><input type="radio" name="type" value="collations"> Declared collations (EC8B / EC8C)</label>
        <label for="file">CSV file</label>
        <input id="file" type="file" name="file" accept=".csv,text/csv" required>
        @error('file')<div class="field-error">{{ $message }}</div>@enderror
        <p></p>
        <button class="button" type="submit">Import</button>
    </form>
</section>

<section class="card">
    <h2>File formats</h2>
    <p class="small">IReV results (irev_status is <code>uploaded</code> or <code>not_uploaded</code>; leave the figures empty for not_uploaded):</p>
    <pre class="small"><code>pu_code,irev_status,accredited,{{ $partyColumns }},rejected,note
EB/212/02633/007,uploaded,1200,610,402,95,18,21,
21202633008,not_uploaded,,,,,,,No sheet on IReV at 9pm</code></pre>
    <p class="small">Declared collations (level is <code>ward</code> for EC8B or <code>lga</code> for EC8C; leave ward empty for an LGA):</p>
    <pre class="small"><code>level,lga,ward,accredited,{{ $partyColumns }},rejected,note
ward,Abakaliki,Abakaliki Ward 01,5400,2710,1802,640,88,97,
lga,Abakaliki,,61020,30112,21440,6010,902,1203,</code></pre>
</section>
@endsection
