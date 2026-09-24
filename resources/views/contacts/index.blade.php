@extends('layouts.app')

@section('title', 'Contacts')

@section('content')
<div class="page-head">
    <h1>Contacts &amp; consent</h1>
    <p class="muted">{{ number_format($totals['all']) }} contacts · {{ number_format($totals['sms']) }} opted in to SMS · {{ number_format($totals['whatsapp']) }} to WhatsApp · {{ number_format($totals['opted_out']) }} opt-outs. Agents come from the USSD service and aren't listed here.</p>
</div>

@include('partials.messaging-tabs')

@if (session('import_errors'))
    <div class="card"><h2>Skipped rows</h2><ul class="small">@foreach (session('import_errors') as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="grid two">
    <section class="card">
        <h2>Add a contact</h2>
        <form method="post" action="{{ route('contacts.store') }}">
            @csrf
            <div class="filters">
                <div><label for="c-name">Name</label><input id="c-name" type="text" name="name" value="{{ old('name') }}"></div>
                <div><label for="c-phone">Phone</label><input id="c-phone" type="text" name="phone" inputmode="tel" value="{{ old('phone') }}" required></div>
                <div><label for="c-type">Type</label><select id="c-type" name="type">@foreach (\App\Models\Contact::TYPES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                <div><label for="c-lga">LGA</label><input id="c-lga" type="text" name="lga" value="{{ old('lga') }}"></div>
            </div>
            @error('phone')<div class="field-error">{{ $message }}</div>@enderror
            <label class="inline"><input type="checkbox" name="sms_opt_in" value="1"> Agreed to SMS updates</label>
            <label class="inline"><input type="checkbox" name="whatsapp_opt_in" value="1"> Agreed to WhatsApp updates</label>
            <button class="button" type="submit">Save</button>
        </form>
    </section>

    <section class="card">
        <h2>Import from CSV</h2>
        <form method="post" action="{{ route('contacts.import') }}" enctype="multipart/form-data">
            @csrf
            <input type="file" name="file" accept=".csv,text/csv" required>
            <p></p>
            <button class="button secondary" type="submit">Import</button>
        </form>
        <pre class="small" style="margin-top:12px"><code>name,phone,type,lga,ward,sms_opt_in,whatsapp_opt_in
Ada Obi,08031234567,supporter,Abakaliki,,yes,no</code></pre>
        <p class="small muted">Mark opt-in "yes" only where the person really agreed. Existing opt-ins are never removed by an import.</p>

        <h2 style="margin-top:16px">Record an opt-out</h2>
        <form method="post" action="{{ route('contacts.opt-out') }}" class="filters">
            @csrf
            <div><label for="o-phone">Phone</label><input id="o-phone" type="text" name="phone" inputmode="tel" required></div>
            <div><label for="o-channel">Channel</label><select id="o-channel" name="channel"><option value="both">SMS and WhatsApp</option><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option></select></div>
            <div class="wide"><button class="button secondary" type="submit">Opt out</button></div>
        </form>
        <p class="small muted">STOP replies to SMS (via Africa's Talking) and WhatsApp are recorded automatically.</p>
    </section>
</div>

<form class="filters" method="get" action="{{ route('contacts') }}">
    <div><label for="q">Search</label><input id="q" type="text" name="q" value="{{ request('q') }}"></div>
    <div><label for="f-type">Type</label><select id="f-type" name="type" data-autosubmit><option value="">All</option>@foreach (\App\Models\Contact::TYPES as $key => $label)<option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>@endforeach</select></div>
    <div><label for="f-lga">LGA</label><select id="f-lga" name="lga" data-autosubmit><option value="">All</option>@foreach ($lgas as $lga)<option @selected(request('lga') === $lga)>{{ $lga }}</option>@endforeach</select></div>
    <div><button class="button secondary" type="submit">Search</button></div>
</form>

<ul class="list two">
    @foreach ($contacts as $contact)
        <li class="item">
            <h3>{{ $contact->name ?? $contact->phone }}</h3>
            <div class="meta">{{ $contact->phone }} · {{ \App\Models\Contact::TYPES[$contact->type] ?? $contact->type }}@if ($contact->lga) · {{ $contact->lga }}@endif · {{ $contact->source }}</div>
            <div class="badges">
                @if ($optedOut->has($contact->phone))<span class="badge bad">Opted out ({{ $optedOut[$contact->phone] }})</span>@endif
                <span class="badge {{ $contact->sms_opt_in_at ? 'good' : '' }}">{{ $contact->sms_opt_in_at ? '✓ SMS' : 'No SMS consent' }}</span>
                <span class="badge {{ $contact->whatsapp_opt_in_at ? 'good' : '' }}">{{ $contact->whatsapp_opt_in_at ? '✓ WhatsApp' : 'No WhatsApp consent' }}</span>
            </div>
            <form method="post" action="{{ route('contacts.destroy', $contact) }}" onsubmit="return confirm('Delete {{ $contact->phone }}?')">@csrf @method('delete')<button class="more-btn" style="display:inline-flex" type="submit">Delete</button></form>
        </li>
    @endforeach
</ul>

<div class="pagination">
    @if ($contacts->previousPageUrl())<a class="button secondary" href="{{ $contacts->previousPageUrl() }}">← Newer</a>@endif
    @if ($contacts->nextPageUrl())<a class="button secondary" href="{{ $contacts->nextPageUrl() }}">Older →</a>@endif
</div>
@endsection
