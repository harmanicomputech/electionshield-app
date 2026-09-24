<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Broadcast;
use App\Models\BroadcastMessage;
use App\Models\PollingUnit;
use App\Services\Broadcasting\Audience;
use App\Services\Broadcasting\BroadcastDispatcher;
use App\Services\Broadcasting\SmsSender;
use App\Services\Broadcasting\WhatsAppSender;
use App\Support\Audit;
use App\Support\Phone;
use App\Support\Time;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * SMS / WhatsApp broadcasts (admins): draft → preview the audience →
 * send now or schedule → delivery report.
 */
class BroadcastController extends Controller
{
    public function __construct(private Audience $audience) {}

    public function index(): View
    {
        return view('broadcasts.index', [
            'broadcasts' => Broadcast::query()->latest()->simplePaginate(20),
        ]);
    }

    public function create(): View
    {
        return view('broadcasts.form', $this->formData(new Broadcast(['channel' => 'sms', 'audience' => ['groups' => ['supporters']]])));
    }

    public function edit(Broadcast $broadcast): View|RedirectResponse
    {
        if (! $broadcast->editable()) {
            return redirect()->route('broadcasts.show', $broadcast);
        }

        return view('broadcasts.form', $this->formData($broadcast));
    }

    public function store(Request $request): RedirectResponse
    {
        $broadcast = Broadcast::create([...$this->validated($request), 'status' => Broadcast::DRAFT, 'created_by' => $request->user()->name]);
        Audit::record('broadcast.created', "Drafted broadcast \"{$broadcast->title}\" ({$broadcast->channel})");

        return redirect()->route('broadcasts.show', $broadcast)->with('status', 'Draft saved. Check the audience below, then send or schedule it.');
    }

    public function update(Request $request, Broadcast $broadcast): RedirectResponse
    {
        abort_unless($broadcast->editable(), 409, 'This broadcast has already been sent.');

        $broadcast->fill([...$this->validated($request), 'status' => Broadcast::DRAFT, 'scheduled_at' => null])->save();
        Audit::record('broadcast.updated', "Edited broadcast \"{$broadcast->title}\"");

        return redirect()->route('broadcasts.show', $broadcast)->with('status', 'Saved as a draft.');
    }

    public function show(Broadcast $broadcast): View
    {
        $recipients = $broadcast->editable() ? $this->audience->recipients($broadcast->audience, $broadcast->channel) : null;

        return view('broadcasts.show', [
            'broadcast' => $broadcast,
            'recipients' => $recipients,
            'counts' => $broadcast->counts(),
            'segments' => self::segments((string) $broadcast->message),
            'failures' => $broadcast->messages()->where('status', 'failed')->selectRaw('failure_reason, count(*) as total')->groupBy('failure_reason')->orderByDesc('total')->limit(10)->get(),
            'ready' => $broadcast->channel === 'whatsapp' ? app(WhatsAppSender::class)->configured() : app(SmsSender::class)->configured(),
        ]);
    }

    public function send(Request $request, Broadcast $broadcast, BroadcastDispatcher $dispatcher): RedirectResponse
    {
        abort_unless($broadcast->editable(), 409, 'This broadcast has already been sent.');

        $expected = $this->audience->recipients($broadcast->audience, $broadcast->channel)->count();
        $request->validate(['confirm' => ['required', 'integer', Rule::in([$expected])]], ['confirm.in' => "Type the number of recipients ({$expected}) to confirm."]);

        $count = $dispatcher->start($broadcast, $request->user()->name);
        Audit::record('broadcast.sent', "Sent broadcast \"{$broadcast->title}\" to {$count} recipients by {$broadcast->channel}");

        return back()->with('status', "Sending to {$count} recipients. Delivery reports appear here as they come in.");
    }

    public function schedule(Request $request, Broadcast $broadcast): RedirectResponse
    {
        abort_unless($broadcast->editable(), 409);

        $at = Carbon::parse($request->validate(['scheduled_at' => ['required', 'date']])['scheduled_at'], config('election.timezone'))->utc();

        if ($at->isPast()) {
            return back()->with('error', 'Choose a time in the future.');
        }

        $broadcast->forceFill(['status' => Broadcast::SCHEDULED, 'scheduled_at' => $at])->save();
        Audit::record('broadcast.scheduled', "Scheduled broadcast \"{$broadcast->title}\" for ".Time::local($at, 'j M Y, g:i A'));

        return back()->with('status', 'Scheduled for '.Time::local($at, 'l j M, g:i A').'. It goes out within a minute of that time.');
    }

    public function cancel(Broadcast $broadcast): RedirectResponse
    {
        if ($broadcast->editable()) {
            $broadcast->forceFill(['status' => Broadcast::CANCELLED, 'scheduled_at' => null])->save();
        } elseif ($broadcast->status === Broadcast::SENDING) {
            // Stop what hasn't gone yet; batches check the status first.
            $broadcast->messages()->where('status', 'queued')->update(['status' => 'skipped', 'failure_reason' => 'Cancelled', 'updated_at' => now()]);
            $broadcast->forceFill(['status' => Broadcast::CANCELLED, 'finished_at' => now()])->save();
        } else {
            return back()->with('error', 'This broadcast has finished.');
        }

        Audit::record('broadcast.cancelled', "Cancelled broadcast \"{$broadcast->title}\"");

        return back()->with('status', 'Cancelled.');
    }

    /**
     * Send the message to one number (your own) before sending to everyone.
     */
    public function test(Request $request, Broadcast $broadcast, SmsSender $sms, WhatsAppSender $whatsapp): RedirectResponse
    {
        $phone = Phone::normalize((string) $request->validate(['phone' => ['required', 'string', 'max:20']])['phone']);

        if ($phone === null) {
            return back()->with('error', 'Enter a Nigerian phone number, e.g. 08031234567.');
        }

        // The senders record each message's status; a test leaves no trace
        // in the broadcast's report.
        DB::beginTransaction();

        try {
            $message = BroadcastMessage::create(['broadcast_id' => $broadcast->id, 'phone' => '#test'.$phone, 'name' => $request->user()->name, 'status' => 'queued']);
            $message->phone = $phone;
            ($broadcast->channel === 'whatsapp' ? $whatsapp : $sms)->send($broadcast, collect([$message]));
            [$status, $reason] = [$message->status, $message->failure_reason];
        } catch (Throwable $e) {
            return back()->with('error', 'Test failed: '.$e->getMessage());
        } finally {
            DB::rollBack();
        }

        Audit::record('broadcast.test', "Sent a test of \"{$broadcast->title}\" to {$phone}");

        return back()->with($status === 'sent' ? 'status' : 'error', $status === 'sent' ? "Test sent to {$phone}." : "Test not accepted: {$reason}");
    }

    /**
     * SMS segments: 160 GSM-7 characters (153 each when split), or 70 / 67
     * when the text needs Unicode (emoji, curly quotes, some accents).
     *
     * @return array{characters: int, segments: int, unicode: bool}
     */
    public static function segments(string $text): array
    {
        $gsm = '@£$¥èéùìòÇ'."\n".'Øø'."\r".'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€';
        $unicode = false;
        $length = 0;

        foreach (mb_str_split($text) as $char) {
            if (mb_strpos($gsm, $char) === false) {
                $unicode = true;
            }
            $length += str_contains('^{}\\[~]|€', $char) ? 2 : 1;
        }

        if ($unicode) {
            $length = mb_strlen($text);
        }

        [$single, $multi] = $unicode ? [70, 67] : [160, 153];

        return ['characters' => $length, 'segments' => $length === 0 ? 0 : ($length <= $single ? 1 : (int) ceil($length / $multi)), 'unicode' => $unicode];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'channel' => ['required', Rule::in(['sms', 'whatsapp'])],
            'message' => [$request->input('channel') === 'sms' ? 'required' : 'nullable', 'string', 'max:918'],
            'template' => [$request->input('channel') === 'whatsapp' ? 'required' : 'nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'template_language' => ['nullable', 'string', 'max:10'],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => [Rule::in(array_keys(Audience::GROUPS))],
            'lgas' => ['nullable', 'array'],
            'lgas.*' => ['string', 'max:100'],
            'wards' => ['nullable', 'array'],
            'wards.*' => ['string', 'max:200'],
        ], ['template.regex' => 'Template names are lower case letters, digits and underscores, as in WhatsApp Manager.']);

        return [
            'title' => $validated['title'],
            'channel' => $validated['channel'],
            'message' => $validated['message'] ?? null,
            'template' => $validated['channel'] === 'whatsapp' ? $validated['template'] : null,
            'template_language' => $validated['channel'] === 'whatsapp' ? ($validated['template_language'] ?? 'en') : null,
            'audience' => ['groups' => array_values($validated['groups']), 'lgas' => array_values($validated['lgas'] ?? []), 'wards' => array_values($validated['wards'] ?? [])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Broadcast $broadcast): array
    {
        return [
            'broadcast' => $broadcast,
            'groups' => Audience::GROUPS,
            'areas' => PollingUnit::query()->whereNotNull('lga')->select('lga', 'ward')->distinct()->orderBy('lga')->orderBy('ward')->get()->groupBy('lga'),
        ];
    }
}
