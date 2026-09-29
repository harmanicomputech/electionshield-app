@foreach ($more as [$name, $label, $path, $covers])
    <a href="{{ route($name) }}">{{ $label }}{{ $name === 'corrections' && $pendingCorrections ? " ({$pendingCorrections})" : '' }}</a>
@endforeach
@if ($more)<hr>@endif
<a href="{{ route('account') }}">My account</a>
<a href="{{ route('push') }}">Notifications</a>
<a href="{{ route('install') }}" data-get-app>📲 Get the app</a>
<form method="post" action="{{ route('logout') }}" data-logout>@csrf<input type="hidden" name="push_endpoint" data-push-endpoint><button type="submit">Log out ({{ $user->name }})</button></form>
