<?php

namespace App\Http\Controllers;

use App\Models\PollingUnit;
use App\Models\TownHallQuestion;
use App\Models\TownHallSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The public digital town hall: schedule, live stream and questions.
 * Nothing from the situation room is shown here.
 */
class TownHallController extends Controller
{
    private const MAX_QUESTIONS_PER_HOUR = 5;

    public function index(): View
    {
        $sessions = TownHallSession::query()->where('published', true)->orderBy('starts_at')->get();

        return view('townhall.index', [
            'current' => $sessions->filter(fn (TownHallSession $s) => $s->phase() !== 'ended')->values(),
            'past' => $sessions->filter(fn (TownHallSession $s) => $s->phase() === 'ended')->sortByDesc('starts_at')->values(),
        ]);
    }

    public function show(TownHallSession $session): View
    {
        abort_unless($session->published, 404);

        return view('townhall.show', [
            'session' => $session,
            'embed' => TownHallSession::embed($session->phase() === 'ended' && $session->recording_url ? $session->recording_url : $session->stream_url),
            'questions' => $this->visible($session),
            'lgas' => PollingUnit::query()->whereNotNull('lga')->distinct()->orderBy('lga')->pluck('lga'),
        ]);
    }

    public function ask(Request $request, TownHallSession $session): RedirectResponse|JsonResponse
    {
        abort_unless($session->published, 404);

        // Bots fill every field; people never see this one.
        if (filled($request->input('website'))) {
            return $this->asked($request, $session);
        }

        if (! $session->acceptsQuestions()) {
            return back()->with('error', 'Questions are closed for this session.');
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'lga' => ['nullable', 'string', 'max:100', Rule::exists('polling_units', 'lga')],
            'body' => ['required', 'string', 'min:10', 'max:280'],
        ], ['body.min' => 'Please write a full question.', 'body.max' => 'Please keep your question under 280 characters.']);

        $ipHash = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

        if (TownHallQuestion::query()->where('ip_hash', $ipHash)->where('created_at', '>', now()->subHour())->count() >= self::MAX_QUESTIONS_PER_HOUR) {
            return back()->withInput()->with('error', 'You have sent several questions already. Please wait a while before sending another.');
        }

        TownHallQuestion::create([
            'town_hall_session_id' => $session->id,
            'name' => trim((string) ($validated['name'] ?? '')) ?: null,
            'lga' => $validated['lga'] ?? null,
            'body' => trim($validated['body']),
            'status' => TownHallQuestion::PENDING,
            'ip_hash' => $ipHash,
        ]);

        return $this->asked($request, $session);
    }

    /**
     * Approved questions, for the page's live refresh.
     */
    public function questions(TownHallSession $session): JsonResponse
    {
        abort_unless($session->published, 404);

        return response()->json([
            'phase' => $session->phase(),
            'questions' => $this->visible($session)->map->toPublicArray()->values(),
        ])->header('Cache-Control', 'public, max-age=10');
    }

    private function visible(TownHallSession $session)
    {
        return $session->questions()
            ->whereIn('status', [TownHallQuestion::APPROVED, TownHallQuestion::ANSWERED])
            ->orderByRaw('case when on_air_at is null then 1 else 0 end')
            ->orderByDesc('on_air_at')
            ->orderBy('moderated_at')
            ->limit(100)
            ->get();
    }

    private function asked(Request $request, TownHallSession $session): RedirectResponse|JsonResponse
    {
        $message = 'Thank you! Your question has been sent. It appears here once a moderator has approved it.';

        return $request->expectsJson()
            ? response()->json(['status' => 'received', 'message' => $message])
            : redirect()->route('townhall.show', $session)->with('status', $message);
    }
}
