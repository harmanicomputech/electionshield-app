<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Broadcast;
use App\Models\TownHallQuestion;
use App\Models\TownHallSession;
use App\Support\Audit;
use App\Support\Time;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Running the town hall: sessions (admins), and the question queue and
 * presenter view (coordinators and admins).
 */
class TownHallManageController extends Controller
{
    public function index(): View
    {
        return view('townhall.manage.index', [
            'sessions' => TownHallSession::query()->withCount(['questions as pending_count' => fn ($q) => $q->where('status', TownHallQuestion::PENDING)])->orderByDesc('starts_at')->get(),
        ]);
    }

    public function create(): View
    {
        return view('townhall.manage.form', ['session' => new TownHallSession(['questions_open' => true, 'published' => true])]);
    }

    public function edit(TownHallSession $session): View
    {
        return view('townhall.manage.form', ['session' => $session]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->slug($data['title'], $data['starts_at']);
        $session = TownHallSession::create([...$data, 'created_by' => $request->user()->name]);
        Audit::record('townhall.created', "Created town hall session \"{$session->title}\"");

        return redirect()->route('townhall.moderate', $session)->with('status', 'Session created. Share the public link below.');
    }

    public function update(Request $request, TownHallSession $session): RedirectResponse
    {
        $session->fill($this->validated($request))->save();
        Audit::record('townhall.updated', "Edited town hall session \"{$session->title}\"");

        return redirect()->route('townhall.moderate', $session)->with('status', 'Saved.');
    }

    public function moderate(Request $request, TownHallSession $session): View
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'answered', 'rejected'])]])['status'] ?? 'pending';

        return view('townhall.manage.moderate', [
            'session' => $session,
            'status' => $status,
            'questions' => $session->questions()->where('status', $status)->orderBy($status === 'pending' ? 'created_at' : 'moderated_at')->limit(200)->get(),
            'counts' => $session->questions()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'onAir' => $session->questions()->whereNotNull('on_air_at')->first(),
        ]);
    }

    /**
     * approve / reject / answered / on_air / off_air. Safe to repeat.
     */
    public function act(Request $request, TownHallSession $session, TownHallQuestion $question): RedirectResponse|JsonResponse
    {
        abort_unless((int) $question->town_hall_session_id === (int) $session->id, 404);
        $action = $request->validate(['action' => ['required', Rule::in(['approve', 'reject', 'answered', 'on_air', 'off_air'])]])['action'];
        $by = ['moderated_by' => $request->user()->name, 'moderated_at' => now()];

        match ($action) {
            'approve' => $question->forceFill(['status' => TownHallQuestion::APPROVED, ...$by]),
            'reject' => $question->forceFill(['status' => TownHallQuestion::REJECTED, 'on_air_at' => null, ...$by]),
            'answered' => $question->forceFill(['status' => TownHallQuestion::ANSWERED, 'answered_at' => $question->answered_at ?? now(), 'on_air_at' => null, 'moderated_by' => $question->moderated_by ?? $by['moderated_by'], 'moderated_at' => $question->moderated_at ?? now()]),
            'on_air' => $question->forceFill(['status' => $question->status === TownHallQuestion::ANSWERED ? TownHallQuestion::ANSWERED : TownHallQuestion::APPROVED, 'on_air_at' => now(), 'moderated_by' => $question->moderated_by ?? $by['moderated_by'], 'moderated_at' => $question->moderated_at ?? now()]),
            'off_air' => $question->forceFill(['on_air_at' => null]),
        };

        // Only one question is on air at a time.
        if ($action === 'on_air') {
            $session->questions()->whereKeyNot($question->id)->whereNotNull('on_air_at')->update(['on_air_at' => null]);
        }

        $question->save();

        return $request->expectsJson() ? response()->json(['status' => $question->status]) : back();
    }

    /**
     * Full-screen view of the question on air, for the host.
     */
    public function present(TownHallSession $session): View
    {
        return view('townhall.manage.present', ['session' => $session, 'question' => $session->questions()->whereNotNull('on_air_at')->first()]);
    }

    public function presentData(TownHallSession $session): JsonResponse
    {
        $question = $session->questions()->whereNotNull('on_air_at')->first();

        return response()->json(['question' => $question?->toPublicArray(), 'pending' => $session->questions()->where('status', TownHallQuestion::PENDING)->count()]);
    }

    /**
     * Start an SMS reminder broadcast to supporters (admins send it).
     */
    public function reminder(Request $request, TownHallSession $session): RedirectResponse
    {
        $link = route('townhall.show', $session);
        $when = Time::local($session->starts_at, 'D j M, g:ia');

        $broadcast = Broadcast::create([
            'title' => 'Town hall reminder: '.$session->title,
            'channel' => 'sms',
            'message' => Str::limit("Town hall: {$session->title}, {$when}. Watch live and send your questions: {$link}", 300, ''),
            'audience' => ['groups' => ['supporters'], 'lgas' => [], 'wards' => []],
            'status' => Broadcast::DRAFT,
            'created_by' => $request->user()->name,
        ]);
        Audit::record('townhall.reminder', "Drafted a reminder broadcast for \"{$session->title}\"");

        return redirect()->route('broadcasts.show', $broadcast)->with('status', 'Reminder drafted. Check the wording and audience, then send or schedule it (for example an hour before).');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'host' => ['nullable', 'string', 'max:150'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'stream_url' => ['nullable', 'url:https', 'max:500'],
            'recording_url' => ['nullable', 'url:https', 'max:500'],
        ]);

        foreach (['stream_url', 'recording_url'] as $field) {
            if (filled($validated[$field] ?? null) && TownHallSession::embed($validated[$field]) === null) {
                throw ValidationException::withMessages([$field => 'Use a YouTube or Facebook video link.']);
            }
        }

        $zone = config('election.timezone');

        return [
            ...$validated,
            'starts_at' => Carbon::parse($validated['starts_at'], $zone)->utc(),
            'ends_at' => filled($validated['ends_at'] ?? null) ? Carbon::parse($validated['ends_at'], $zone)->utc() : null,
            'questions_open' => $request->boolean('questions_open'),
            'published' => $request->boolean('published'),
        ];
    }

    private function slug(string $title, Carbon $startsAt): string
    {
        $base = Str::limit(Str::slug($title), 60, '') ?: 'town-hall';
        $slug = $base.'-'.$startsAt->copy()->setTimezone(config('election.timezone'))->format('Md');
        $n = 1;

        while (TownHallSession::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$startsAt->copy()->setTimezone(config('election.timezone'))->format('Md').'-'.(++$n);
        }

        return strtolower($slug);
    }
}
