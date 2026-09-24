@extends('layouts.app')

@section('title', 'Audit log')

@section('content')
<div class="page-head">
    <h1>Audit log</h1>
    <p class="muted">Logins, user changes, syncs and settings.</p>
</div>

<div class="card flush">
    <div class="table-wrap">
        <table class="stack">
            <thead><tr><th>What</th><th>Who</th><th>When</th></tr></thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="key">{{ $log->description }}</td>
                        <td data-label="Who">{{ $log->user_name }}</td>
                        <td data-label="When">{{ \App\Support\Time::local($log->created_at, 'j M, g:i A') }}</td>
                    </tr>
                @empty
                    <tr><td class="key muted">Nothing recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="pagination">
    @if ($logs->previousPageUrl())<a class="button secondary" href="{{ $logs->previousPageUrl() }}">← Newer</a>@endif
    @if ($logs->nextPageUrl())<a class="button secondary" href="{{ $logs->nextPageUrl() }}">Older →</a>@endif
</div>
@endsection
