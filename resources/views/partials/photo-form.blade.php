{{-- Photo upload form. Expects $action; optional $reference (hidden) and $withReference (show the field). --}}
<form method="post" action="{{ $action }}" enctype="multipart/form-data" data-photo="{{ $label ?? 'EC8A photo' }}">
    @csrf
    @if ($withReference ?? false)
        <label for="reference">Result reference (from the agent's SMS, e.g. RS784321)</label>
        <input id="reference" type="text" name="reference" value="{{ old('reference', $reference ?? '') }}" autocomplete="off" autocapitalize="characters" required>
        @error('reference')<div class="field-error">{{ $message }}</div>@enderror
    @endif
    <label for="photo">Photo of the EC8A sheet</label>
    <input id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment" required>
    @error('photo')<div class="field-error">{{ $message }}</div>@enderror
    <p class="small muted">Lay the sheet flat in good light and fill the frame, so every figure can be read. It is shrunk on the phone before sending.</p>
    @if ($withNote ?? false)
        <label for="photo-note">Note (optional)</label>
        <input id="photo-note" type="text" name="note" maxlength="500">
    @endif
    <p></p>
    <button class="button" type="submit">Upload photo</button>
    <div class="queue-state" data-queue-state aria-live="polite"></div>
</form>
