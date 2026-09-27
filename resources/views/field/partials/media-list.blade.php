@php
    $files = ($attachments[$reference] ?? collect())->count() + ($photos[$reference] ?? collect())->count();
@endphp
<p class="small muted">{{ $files ? $files.' '.($files === 1 ? 'file' : 'files').' attached' : 'No photos or videos yet' }}</p>
<details>
    <summary class="button secondary">Add photo or video</summary>
    <form method="post" action="{{ route('field.media', $reference) }}" enctype="multipart/form-data" data-field-form="Files for {{ $reference }}">
        @csrf
        @include('partials.media-input', ['id' => 'media-'.$reference, 'label' => 'Photos or videos', 'maxMb' => $maxMb])
        <button class="button" type="submit">Send</button>
        <div class="queue-state" data-queue-state aria-live="polite"></div>
    </form>
</details>
