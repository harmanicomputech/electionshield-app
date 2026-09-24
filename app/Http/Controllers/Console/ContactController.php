<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\OptOut;
use App\Support\Audit;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The broadcast list (admins): supporters and others, with their consent.
 */
class ContactController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        return view('contacts.index', [
            'contacts' => Contact::query()
                ->when($request->query('type'), fn (Builder $query, $type) => $query->where('type', $type))
                ->when($request->query('lga'), fn (Builder $query, $lga) => $query->where('lga', $lga))
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%')))
                ->latest()
                ->simplePaginate(50)
                ->withQueryString(),
            'totals' => [
                'all' => Contact::query()->count(),
                'sms' => Contact::query()->whereNotNull('sms_opt_in_at')->count(),
                'whatsapp' => Contact::query()->whereNotNull('whatsapp_opt_in_at')->count(),
                'opted_out' => OptOut::query()->count(),
            ],
            'optedOut' => OptOut::query()->pluck('channel', 'phone'),
            'lgas' => Contact::query()->whereNotNull('lga')->distinct()->orderBy('lga')->pluck('lga'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'type' => ['required', Rule::in(array_keys(Contact::TYPES))],
            'lga' => ['nullable', 'string', 'max:100'],
            'ward' => ['nullable', 'string', 'max:100'],
            'sms_opt_in' => ['nullable', 'boolean'],
            'whatsapp_opt_in' => ['nullable', 'boolean'],
        ]);

        $phone = Phone::normalize($validated['phone']);

        if ($phone === null) {
            return back()->withInput()->withErrors(['phone' => 'Enter a Nigerian phone number, e.g. 08031234567.']);
        }

        $this->save($phone, $validated, 'manual');
        Audit::record('contact.saved', "Saved contact {$phone}");

        return back()->with('status', "Saved {$phone}.");
    }

    /**
     * CSV: name,phone,type,lga,ward,sms_opt_in,whatsapp_opt_in
     * Only mark opt-in "yes" where the person really agreed (a signed form,
     * a recorded reply): it is what makes a supporter reachable.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:4096', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($c) => strtolower(trim((string) $c, " \t\n\r\0\x0B\u{FEFF}")), fgetcsv($handle, escape: '\\') ?: []);
        $saved = 0;
        $skipped = [];
        $line = 1;
        $yes = fn ($value) => in_array(strtolower(trim((string) $value)), ['1', 'yes', 'y', 'true'], true);

        DB::transaction(function () use ($handle, $header, $yes, &$saved, &$skipped, &$line) {
            while (($row = fgetcsv($handle, escape: '\\')) !== false) {
                $line++;
                $data = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
                $phone = Phone::normalize($data['phone'] ?? '');
                $type = strtolower(trim($data['type'] ?? '')) ?: 'supporter';

                if ($phone === null || ! array_key_exists($type, Contact::TYPES)) {
                    count($skipped) < 50 && $skipped[] = "Line {$line}: ".($phone === null ? 'invalid phone' : "unknown type \"{$type}\"");

                    continue;
                }

                $this->save($phone, [
                    'name' => trim($data['name'] ?? '') ?: null,
                    'type' => $type,
                    'lga' => trim($data['lga'] ?? '') ?: null,
                    'ward' => trim($data['ward'] ?? '') ?: null,
                    'sms_opt_in' => $yes($data['sms_opt_in'] ?? ''),
                    'whatsapp_opt_in' => $yes($data['whatsapp_opt_in'] ?? ''),
                ], 'import');
                $saved++;
            }
        });

        fclose($handle);
        Audit::record('contact.import', "Imported {$saved} contacts".($skipped ? ' ('.count($skipped).' rows skipped)' : ''));

        return back()->with($skipped ? 'error' : 'status', "Imported {$saved} contacts.")->with('import_errors', $skipped);
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        $contact->delete();
        Audit::record('contact.deleted', "Deleted contact {$contact->phone}");

        return back()->with('status', "Deleted {$contact->phone}.");
    }

    /**
     * Record a STOP received some other way (a call, a letter).
     */
    public function optOut(Request $request): RedirectResponse
    {
        $validated = $request->validate(['phone' => ['required', 'string', 'max:20'], 'channel' => ['required', Rule::in(['sms', 'whatsapp', 'both'])]]);
        $phone = Phone::normalize($validated['phone']);

        if ($phone === null) {
            return back()->with('error', 'Enter a Nigerian phone number.');
        }

        foreach ($validated['channel'] === 'both' ? ['sms', 'whatsapp'] : [$validated['channel']] as $channel) {
            OptOut::record($phone, $channel, 'manual');
        }
        Audit::record('contact.opt_out', "Recorded an opt-out for {$phone} ({$validated['channel']})");

        return back()->with('status', "{$phone} will not get further broadcasts.");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(string $phone, array $data, string $source): Contact
    {
        $contact = Contact::query()->firstOrNew(['phone' => $phone]);

        $contact->fill([
            'name' => $data['name'] ?? $contact->name,
            'type' => $data['type'] ?? $contact->type ?? 'supporter',
            'lga' => $data['lga'] ?? $contact->lga,
            'ward' => $data['ward'] ?? $contact->ward,
            'source' => $contact->source ?? $source,
        ]);
        // Opt-in is only ever added here, never silently removed.
        if (! empty($data['sms_opt_in'])) {
            $contact->sms_opt_in_at ??= now();
        }
        if (! empty($data['whatsapp_opt_in'])) {
            $contact->whatsapp_opt_in_at ??= now();
        }
        $contact->save();

        return $contact;
    }
}
