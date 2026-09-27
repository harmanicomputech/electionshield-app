{{-- Photos and videos for an agent's report: shown as a picker with previews (app.js). --}}
<div class="media-input" data-media-input data-max-mb="{{ $maxMb }}" data-max-files="{{ config('election.media.max_files') }}">
    <label for="{{ $id ?? 'media' }}">{{ $label ?? 'Photos or videos (optional)' }}</label>
    <input id="{{ $id ?? 'media' }}" type="file" name="media[]" accept="image/*,video/*" multiple>
    <p class="small muted">Up to {{ config('election.media.max_files') }} files. Photos are shrunk on your phone; videos up to {{ $maxMb }} MB (about {{ max(1, intdiv($maxMb, 25)) }} min).</p>
    <div class="media-previews" data-media-previews></div>
    @error('media')<div class="field-error">{{ $message }}</div>@enderror
    @error('media.*')<div class="field-error">{{ $message }}</div>@enderror
</div>
