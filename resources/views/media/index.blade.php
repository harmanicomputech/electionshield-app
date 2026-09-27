@extends('layouts.app')

@section('title', 'Photos & videos')

@section('content')
<div class="page-head">
    <h1>Incident photos &amp; videos</h1>
    <p class="muted">Sent by agents with their incident reports and results. EC8A sheet photos are on the EC8A photos tab.</p>
</div>

@include('partials.results-tabs')

<nav class="tabs" aria-label="Kind">
    @foreach (['all' => 'All', 'image' => 'Photos', 'video' => 'Videos'] as $key => $label)
        <a href="{{ route('media', ['kind' => $key]) }}" class="{{ $kind === $key ? 'on' : '' }}">{{ $label }} <b>{{ $key === 'all' ? $counts->sum() : ($counts[$key] ?? 0) }}</b></a>
    @endforeach
</nav>

@if ($items->isEmpty())
    <div class="card"><p class="muted">Nothing here yet.</p></div>
@endif

<ul class="photo-grid">
    @foreach ($items as $item)
        @php
            $incident = $incidents[$item->reference] ?? null;
            $result = $results[$item->reference] ?? null;
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
                @else
                    {{ $item->reference }}
                @endif
            </h3>
            <div class="meta">{{ ($incident ?? $result)?->lga }}{{ ($incident ?? $result)?->ward ? ' › '.($incident ?? $result)->ward : '' }} · {{ $item->uploaded_by ?? 'agent' }} · {{ \App\Support\Time::local($item->created_at, 'j M, g:i A') }} · {{ $item->sizeLabel() }}</div>
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
