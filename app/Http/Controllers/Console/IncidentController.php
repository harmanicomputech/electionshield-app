<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The live incident feed. Coordinators acknowledge an incident (someone is
 * on it) and resolve it with a note. Both are safe to repeat, so actions
 * queued offline can be replayed.
 */
class IncidentController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['unresolved', Incident::OPEN, Incident::ACKNOWLEDGED, Incident::RESOLVED, 'all'])],
            'urgent' => ['nullable', 'boolean'],
            'type' => ['nullable', 'string', 'max:30'],
            'lga' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'unresolved';

        $base = Incident::query()->where('rehearsal', Settings::showingRehearsal());

        $incidents = (clone $base)
            ->when($status === 'unresolved', fn (Builder $query) => $query->unresolved())
            ->when(in_array($status, [Incident::OPEN, Incident::ACKNOWLEDGED, Incident::RESOLVED], true), fn (Builder $query) => $query->withResponseStatus($status))
            ->when($request->boolean('urgent'), fn (Builder $query) => $query->where('urgent', true))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['lga'] ?? null, fn (Builder $query, string $lga) => $query->where('lga', $lga))
            ->with('pollingUnit:code,name')
            // Urgent and unhandled first, then newest.
            ->orderByRaw('case when resolved_at is null and acknowledged_at is null and urgent = 1 then 0 else 1 end')
            ->latest('reported_at')
            ->latest('id')
            ->simplePaginate(30)
            ->withQueryString();

        return view('incidents.index', [
            'incidents' => $incidents,
            'status' => $status,
            'counts' => [
                Incident::OPEN => (clone $base)->withResponseStatus(Incident::OPEN)->count(),
                Incident::ACKNOWLEDGED => (clone $base)->withResponseStatus(Incident::ACKNOWLEDGED)->count(),
                Incident::RESOLVED => (clone $base)->withResponseStatus(Incident::RESOLVED)->count(),
            ],
            'urgentOpen' => (clone $base)->withResponseStatus(Incident::OPEN)->where('urgent', true)->count(),
            'types' => (clone $base)->select('type', 'type_label')->distinct()->orderBy('type')->get(),
            'lgas' => (clone $base)->whereNotNull('lga')->distinct()->orderBy('lga')->pluck('lga'),
        ]);
    }

    public function acknowledge(Request $request, Incident $incident): RedirectResponse|JsonResponse
    {
        if ($incident->acknowledged_at === null) {
            $incident->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()->name])->save();
            Audit::record('incident.acknowledged', "Acknowledged incident {$incident->reference} ({$incident->label()}) at PU {$incident->polling_unit_code}");
        }

        return $this->done($request, $incident, "Acknowledged {$incident->reference}.");
    }

    public function resolve(Request $request, Incident $incident): RedirectResponse|JsonResponse
    {
        $note = $request->validate(['resolution_note' => ['nullable', 'string', 'max:1000']])['resolution_note'] ?? null;

        if ($incident->resolved_at === null) {
            $incident->forceFill([
                'acknowledged_at' => $incident->acknowledged_at ?? now(),
                'acknowledged_by' => $incident->acknowledged_by ?? $request->user()->name,
                'resolved_at' => now(),
                'resolved_by' => $request->user()->name,
                'resolution_note' => $note,
            ])->save();
            Audit::record('incident.resolved', "Resolved incident {$incident->reference} ({$incident->label()})".($note ? ": {$note}" : ''));
        }

        return $this->done($request, $incident, "Resolved {$incident->reference}.");
    }

    public function reopen(Request $request, Incident $incident): RedirectResponse|JsonResponse
    {
        if ($incident->resolved_at !== null) {
            $incident->forceFill(['resolved_at' => null, 'resolved_by' => null, 'resolution_note' => null])->save();
            Audit::record('incident.reopened', "Reopened incident {$incident->reference} ({$incident->label()})");
        }

        return $this->done($request, $incident, "Reopened {$incident->reference}.");
    }

    private function done(Request $request, Incident $incident, string $message): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['status' => $incident->responseStatus(), 'message' => $message])
            : back()->with('status', $message);
    }
}
