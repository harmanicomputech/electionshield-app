{{-- Click-to-load player, so the page stays light until someone presses play. Expects $embed, $title. --}}
@if ($embed)
    <div class="player" data-embed="{{ $embed['src'] }}" data-title="{{ $title }}">
        <button type="button" class="player-start" aria-label="Play: {{ $title }}">
            @if ($embed['thumbnail'])<img src="{{ $embed['thumbnail'] }}" alt="" loading="lazy" width="480" height="360">@endif
            <span class="play" aria-hidden="true">▶</span>
            <span class="player-label">Tap to watch{{ $embed['provider'] === 'facebook' ? ' on Facebook' : '' }}</span>
        </button>
    </div>
@endif
