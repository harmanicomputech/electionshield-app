{{-- Per-person location setting. Expects $id and $value (role|always|never). --}}
<label for="{{ $id }}">Record their location</label>
<select id="{{ $id }}" name="track_location">
    <option value="role" @selected($value === 'role')>As their role says (Roles → “Location is recorded”)</option>
    <option value="always" @selected($value === 'always')>Always (shows on the People map)</option>
    <option value="never" @selected($value === 'never')>Never</option>
</select>
