@extends('layouts.app')

@section('title', 'Photos & videos')

@section('content')
<div class="page-head">
    <h1>Photos &amp; videos</h1>
    <p class="muted">Sent by agents with their incident reports, materials reports and results. EC8A sheet photos are on the EC8A photos tab.</p>
</div>

@include('partials.results-tabs')

<nav class="tabs" aria-label="Kind">
    @foreach (['all' => 'All', 'image' => 'Photos', 'video' => 'Videos', 'materials' => 'Materials'] as $key => $label)
        <a href="{{ route('media', ['kind' => $key]) }}" class="{{ $kind === $key && ! $reference ? 'on' : '' }}">{{ $label }} <b>{{ match ($key) { 'all' => $counts->sum(), 'materials' => $materialsCount, default => $counts[$key] ?? 0 } }}</b></a>
    @endforeach
</nav>

@if ($reference)
    <p class="small">Showing the files for {{ $reference }} · <a href="{{ route('media') }}">show all</a></p>
@endif

@if ($items->isEmpty())
    <div class="card"><p class="muted">Nothing here yet.</p></div>
@endif

<ul class="photo-grid">
    @foreach ($items as $item)
        @php
            $incident = $incidents[$item->reference] ?? null;
            $result = $results[$item->reference] ?? null;
            $report = $materials[$item->reference] ?? null;
            $record = $incident ?? $result ?? $report;
        @endphp
        <li class="item">
            @if ($item->isVideo())
                <video class="thumb" src="{{ route('media.file', $item) }}" controls preload="metadata" playsinline></video>
            @else
                <a class="thumb" href="{{ route('media.file', $item) }}" target="_blank" rel="noopener"><img src="{{ route('media.file', $item) }}" alt="Photo for {{ $item->reference }}" loading="lazy"></a>
            @endif
            <h3>
                @if ($incident)
                    <a href="{{ route('incidents', ['status' => 'unresolved']) }}#incident-{{ $incident->reference }}">{{ $incident->label() }} · {{ $item->reference }}</a>
                @elseif ($report)
                    Materials: {{ $report->status_label ?? str_replace('_', ' ', $report->status) }} · PU {{ $report->polling_unit_code }}
                @else
                    {{ $item->reference }}
                @endif
            </h3>
            <div class="meta">{{ $record?->lga }}{{ $record?->ward ? ' › '.$record->ward : '' }}@if ($report) · reported {{ \App\Support\Time::local($report->reported_at, 'g:i A') }}@endif · {{ $item->uploaded_by ?? 'agent' }} · {{ \App\Support\Time::local($item->created_at, 'j M, g:i A') }} · {{ $item->sizeLabel() }}</div>
            <div class="badges">
                <span class="badge {{ $item->review_status === 'checked' ? 'good' : ($item->review_status === 'doubtful' ? 'bad' : '') }}">{{ ['checked' => '✓ Checked', 'doubtful' => '✗ Doubtful'][$item->review_status] ?? 'Not checked' }}</span>
            </div>
            @can('review_media')
                <form method="post" action="{{ route('media.review', $item) }}" class="actions" data-queue="Review {{ $item->reference }}">
                    @csrf
                    <button class="button secondary" type="submit" name="review_status" value="checked">Checked</button>
                    <button class="button secondary" type="submit" name="review_status" value="doubtful">Doubtful</button>
                </form>
            @endcan
            @can('delete_evidence')
                <form method="post" action="{{ route('media.destroy', $item) }}" onsubmit="return confirm('Delete this file? It is evidence.')">
                    @csrf @method('delete')
                    <button class="button danger" type="submit">Delete</button>
                </form>
            @endcan
        </li>
    @endforeach
</ul>

<div class="pagination">
    @if ($items->previousPageUrl())<a class="button secondary" href="{{ $items->previousPageUrl() }}">← Newer</a>@endif
    @if ($items->nextPageUrl())<a class="button secondary" href="{{ $items->nextPageUrl() }}">Older →</a>@endif
</div>
@endsection
