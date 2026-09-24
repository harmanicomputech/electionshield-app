<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\OptOut;
use App\Models\PollingUnit;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Public sign-up for election updates: the opt-in that makes a supporter
 * reachable by broadcast. Consent is explicit and per channel.
 */
class JoinController extends Controller
{
    public function show(): View
    {
        return view('join', ['lgas' => PollingUnit::query()->whereNotNull('lga')->distinct()->orderBy('lga')->pluck('lga')]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Bots fill every field; people never see this one.
        if (filled($request->input('website'))) {
            return back()->with('status', 'Thank you.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'lga' => ['nullable', 'string', 'max:100', Rule::exists('polling_units', 'lga')],
            'sms' => ['nullable', 'boolean'],
            'whatsapp' => ['nullable', 'boolean'],
            'consent' => ['accepted'],
        ], ['consent.accepted' => 'Please tick the box to agree to receive messages.']);

        $phone = Phone::normalize($validated['phone']);

        if ($phone === null) {
            return back()->withInput()->withErrors(['phone' => 'Enter a Nigerian phone number, e.g. 08031234567.']);
        }

        if (! $request->boolean('sms') && ! $request->boolean('whatsapp')) {
            return back()->withInput()->withErrors(['sms' => 'Choose SMS, WhatsApp or both.']);
        }

        $contact = Contact::query()->firstOrNew(['phone' => $phone]);
        $contact->fill(['name' => $validated['name'], 'lga' => $validated['lga'] ?? $contact->lga, 'type' => $contact->type ?? 'supporter', 'source' => $contact->source ?? 'join form']);

        foreach (['sms' => 'sms_opt_in_at', 'whatsapp' => 'whatsapp_opt_in_at'] as $channel => $column) {
            if ($request->boolean($channel)) {
                $contact->{$column} ??= now();
                // Joining again is a fresh opt-in.
                OptOut::query()->where(['phone' => $phone, 'channel' => $channel])->delete();
            }
        }
        $contact->save();

        return redirect()->route('join')->with('status', 'Thank you! You will receive election updates. Reply STOP at any time to stop them.');
    }
}
