{{-- Thumbnails of a report's photos and videos. $files: Attachment collection; $sheets: optional Ec8aPhoto collection. --}}
@if (($files ?? collect())->isNotEmpty() || ($sheets ?? collect())->isNotEmpty())
    <div class="media-strip">
        @foreach ($sheets ?? [] as $sheet)
            <a href="{{ route('photos.show', $sheet) }}" title="EC8A photo"><img src="{{ route('photos.image', [$sheet, 'thumb']) }}" alt="EC8A photo" loading="lazy"></a>
        @endforeach
        @foreach ($files ?? [] as $file)
            @if ($file->isVideo())
                <a href="{{ route('media.file', $file) }}" target="_blank" rel="noopener" title="Video, {{ $file->sizeLabel() }}">
                    <video src="{{ route('media.file', $file) }}#t=0.5" preload="metadata" muted playsinline></video><span class="play" aria-hidden="true">▶</span>
                </a>
            @else
                <a href="{{ route('media.file', $file) }}" target="_blank" rel="noopener" title="Photo, {{ $file->sizeLabel() }}"><img src="{{ route('media.file', $file) }}" alt="Photo" loading="lazy"></a>
            @endif
        @endforeach
    </div>
@endif
