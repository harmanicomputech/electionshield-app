<?php

namespace App\Http\Controllers\Field;

use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Ec8aPhoto;
use App\Models\Incident;
use App\Models\MaterialReport;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\Result;
use App\Models\UserLocation;
use App\Services\Collation;
use App\Services\FieldMedia;
use App\Services\FieldSubmitter;
use App\Services\LocationRecorder;
use App\Support\Audit;
use App\Support\FieldOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The agent pages: everything the USSD menu does (check in, materials,
 * results and corrections, incidents) plus photos and videos, and a history
 * of what the agent sent. Submissions go to the USSD service (FieldSubmitter)
 * so both channels follow the same rules. Forms are sent by app.js, which
 * keeps them on the phone when there is no network (JSON replies); without
 * JavaScript they post normally (redirects).
 */
class FieldController extends Controller
{
    public function __construct(private FieldSubmitter $submitter, private FieldMedia $media) {}

    public function home(Request $request): View
    {
        $agent = $this->agent($request);
        $phone = $request->user()->phone;
        $code = $agent?->polling_unit_code;

        return view('field.home', [
            'agent' => $agent,
            'unit' => $code ? PollingUnit::query()->where('code', $code)->first() : null,
            'presence' => Presence::query()->where('agent_phone', $phone)->latest('confirmed_at')->first(),
            'materials' => MaterialReport::query()->where('agent_phone', $phone)->latest('reported_at')->first(),
            'result' => $code ? Result::query()->where('polling_unit_code', $code)->whereIn('status', [ResultStatus::Accepted, ResultStatus::Pending])->latest('submitted_at')->first() : null,
            'incidents' => Incident::query()->where('agent_phone', $phone)->count(),
            'statuses' => FieldOptions::materialStatuses(),
            'maxMb' => (int) config('election.media.max_video_mb'),
        ]);
    }

    /**
     * Check in: only with the phone's location (app.js reads it; the phone
     * asks for permission when the app opens). The position is kept with
     * the check-in for the situation room; the agent isn't shown it.
     */
    public function presence(Request $request, LocationRecorder $locations): JsonResponse|RedirectResponse
    {
        $agent = $this->agentOrFail($request);
        $data = $request->validate([
            'polling_unit' => ['nullable', 'string', 'max:30'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required_with:latitude', 'numeric', 'between:-180,180'],
            'location_accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'located_at' => ['nullable', 'integer', 'min:0'],
        ], ['latitude.required' => 'Your location is needed to check in. Allow location for this site and try again.']);

        $outcome = $this->submitter->submit('presence', $agent, array_filter(['polling_unit' => $data['polling_unit'] ?? null]));

        if ($outcome['ok']) {
            $location = [
                'status' => UserLocation::OK,
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'accuracy' => isset($data['location_accuracy']) ? (float) $data['location_accuracy'] : null,
                'located_at' => filled($data['located_at'] ?? null) ? Carbon::createFromTimestampMs((int) $data['located_at']) : now(),
            ];

            if ($presence = Presence::query()->where('ussd_id', (int) ($outcome['record']['id'] ?? 0))->first()) {
                $presence->forceFill([
                    'latitude' => $location['latitude'],
                    'longitude' => $location['longitude'],
                    'location_accuracy' => $location['accuracy'],
                    'located_at' => $location['located_at'],
                ])->save();
            }

            $locations->record($request->user(), $location, 'check-in'.($presence ? " PU {$presence->polling_unit_code}" : ''));
        }

        return $this->reply($request, $outcome, 'field');
    }

    public function materials(Request $request): JsonResponse|RedirectResponse
    {
        $agent = $this->agentOrFail($request);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(FieldOptions::materialStatuses()))],
            'polling_unit' => ['nullable', 'string', 'max:30'],
            ...FieldMedia::rules(),
            // "Arrived" needs proof: a photo or video of the materials.
            'media' => [Rule::requiredIf(in_array($request->input('status'), MaterialReport::NEEDS_EVIDENCE, true)), 'array', 'max:'.config('election.media.max_files')],
        ], ['media.required' => 'Add a photo or video of the materials to report them as arrived.']);

        $outcome = $this->submitter->submit('materials', $agent, array_filter(['status' => $data['status'], 'polling_unit' => $data['polling_unit'] ?? null]));

        if ($outcome['ok'] && isset($outcome['record']['id'])) {
            $outcome['reference'] = MaterialReport::referenceFor((int) $outcome['record']['id']);
            $outcome = $this->withMedia($request, $outcome, false);
        }

        return $this->reply($request, $outcome, 'field');
    }

    public function resultForm(Request $request): View
    {
        $agent = $this->agent($request);
        $code = $agent?->polling_unit_code;

        return view('field.result', [
            'agent' => $agent,
            'unit' => $code ? PollingUnit::query()->where('code', $code)->first() : null,
            'existing' => $code ? Result::query()->where('polling_unit_code', $code)->where('status', ResultStatus::Accepted)->first() : null,
            'parties' => Collation::parties(),
            'maxMb' => (int) config('election.media.max_video_mb'),
        ]);
    }

    public function submitResult(Request $request): JsonResponse|RedirectResponse
    {
        $agent = $this->agentOrFail($request);
        $parties = Collation::parties();

        $data = $request->validate([
            'polling_unit' => ['nullable', 'string', 'max:30'],
            'accredited_voters' => ['required', 'integer', 'min:0', 'max:999999'],
            'votes' => ['required', 'array'],
            ...collect($parties)->mapWithKeys(fn (string $party) => ["votes.{$party}" => ['required', 'integer', 'min:0', 'max:999999']])->all(),
            'rejected_votes' => ['required', 'integer', 'min:0', 'max:999999'],
            'correction' => ['nullable', 'boolean'],
            ...FieldMedia::rules(),
        ], [], ['accredited_voters' => 'accredited voters', 'rejected_votes' => 'rejected votes', ...collect($parties)->mapWithKeys(fn (string $party) => ["votes.{$party}" => "{$party} votes"])->all()]);

        $outcome = $this->submitter->submit('result', $agent, array_filter([
            'polling_unit' => $data['polling_unit'] ?? null,
            'accredited_voters' => $data['accredited_voters'],
            'votes' => collect($parties)->mapWithKeys(fn (string $party) => [$party => (int) $data['votes'][$party]])->all(),
            'rejected_votes' => $data['rejected_votes'],
            'correction' => $request->boolean('correction') ? 1 : 0,
        ], fn ($value) => $value !== null));

        return $this->reply($request, $this->withMedia($request, $outcome, true), 'field.history');
    }

    public function incidentForm(Request $request): View
    {
        return view('field.incident', [
            'agent' => $this->agent($request),
            'types' => FieldOptions::incidentTypes(),
            'maxMb' => (int) config('election.media.max_video_mb'),
        ]);
    }

    public function submitIncident(Request $request): JsonResponse|RedirectResponse
    {
        $agent = $this->agentOrFail($request);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(FieldOptions::incidentTypes()))],
            'note' => ['required', 'string', 'min:2', 'max:1000'],
            'polling_unit' => ['nullable', 'string', 'max:30'],
            ...FieldMedia::rules(),
        ], [], ['note' => 'what happened']);

        $outcome = $this->submitter->submit('incident', $agent, array_filter([
            'type' => $data['type'],
            'note' => $data['note'],
            'polling_unit' => $data['polling_unit'] ?? null,
        ]));

        return $this->reply($request, $this->withMedia($request, $outcome, false), 'field.history');
    }

    public function history(Request $request): View
    {
        $phone = $request->user()->phone;
        $results = Result::query()->with('votes')->where('agent_phone', $phone)->latest('submitted_at')->limit(50)->get();
        $incidents = Incident::query()->where('agent_phone', $phone)->latest('reported_at')->limit(50)->get();
        $references = $results->pluck('reference')->merge($incidents->pluck('reference'));

        return view('field.history', [
            'results' => $results,
            'incidents' => $incidents,
            'attachments' => Attachment::query()->whereIn('reference', $references)->get()->groupBy('reference'),
            'photos' => Ec8aPhoto::query()->whereIn('result_reference', $references)->get()->groupBy('result_reference'),
            'maxMb' => (int) config('election.media.max_video_mb'),
        ]);
    }

    /**
     * Add photos or videos to one of the agent's own reports.
     */
    public function addMedia(Request $request, string $reference): JsonResponse|RedirectResponse
    {
        $phone = $request->user()->phone;
        $result = Result::query()->where(['reference' => $reference, 'agent_phone' => $phone])->exists();
        abort_unless($result || Incident::query()->where(['reference' => $reference, 'agent_phone' => $phone])->exists(), 404);

        $request->validate([...FieldMedia::rules(), 'media' => ['required', 'array', 'min:1', 'max:'.config('election.media.max_files')]]);
        $counts = $this->media->attach($request->file('media', []), $reference, $result, $request->user());
        Audit::record('media.added', "Added {$this->describe($counts)} to {$reference}");

        return $this->reply($request, ['ok' => true, 'status' => 201, 'message' => "Added {$this->describe($counts)} to {$reference}.", 'reference' => $reference, 'retry' => false], 'field.history');
    }

    /**
     * @param  array{ok: bool, status: int, message: string, reference: ?string, retry: bool}  $outcome
     * @return array{ok: bool, status: int, message: string, reference: ?string, retry: bool}
     */
    private function withMedia(Request $request, array $outcome, bool $isResult): array
    {
        $files = $request->file('media', []);

        if (! $outcome['ok'] || ! $outcome['reference'] || $files === []) {
            return $outcome;
        }

        $counts = $this->media->attach($files, $outcome['reference'], $isResult, $request->user());
        $outcome['message'] = rtrim($outcome['message'], '.').". With {$this->describe($counts)}.";

        return $outcome;
    }

    /**
     * @param  array{photos: int, videos: int}  $counts
     */
    private function describe(array $counts): string
    {
        return collect(['photos' => ['photo', 'photos'], 'videos' => ['video', 'videos']])
            ->filter(fn ($words, $key) => $counts[$key] > 0)
            ->map(fn ($words, $key) => $counts[$key].' '.($counts[$key] === 1 ? $words[0] : $words[1]))
            ->implode(' and ') ?: 'no files';
    }

    /**
     * @param  array{ok: bool, status: int, message: string, reference: ?string, retry: bool}  $outcome
     */
    private function reply(Request $request, array $outcome, string $then): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $outcome['message'],
                'reference' => $outcome['reference'],
                'url' => $outcome['ok'] ? route($then) : null,
                'retry' => $outcome['retry'],
            ], $outcome['ok'] ? 201 : ($outcome['retry'] ? 503 : 422));
        }

        return $outcome['ok']
            ? redirect()->route($then)->with('status', $outcome['message'])
            : back()->withInput()->with('error', $outcome['message']);
    }

    private function agent(Request $request): ?Agent
    {
        return Agent::query()->where('phone_number', $request->user()->phone)->first();
    }

    private function agentOrFail(Request $request): Agent
    {
        return $this->agent($request) ?? abort(403, 'Your agent record was not found. Ask your coordinator to check your phone number.');
    }
}
