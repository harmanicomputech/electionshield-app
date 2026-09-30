<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Volunteer;
use App\Support\Audit;
use App\Support\Permission;
use App\Support\Settings;
use App\Support\Time;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "How can you help?" sign-ups from USSD (anyone can dial): filter by how
 * they can help and where, call or WhatsApp them, mark them contacted, and
 * download the list.
 */
class VolunteerController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $base = Volunteer::query()->where('rehearsal', Settings::showingRehearsal());
        $filtered = $this->query($filters);

        // How many can help with each thing (in the chosen area, before the role filter).
        $inArea = $this->query([...$filters, 'role' => null])->get(['roles']);
        $roleCounts = collect(Volunteer::ROLES)->map(fn ($label, $key) => $inArea->filter(fn (Volunteer $volunteer) => in_array($key, $volunteer->roles ?? [], true))->count());

        return view('volunteers.index', [
            'filters' => $filters,
            'volunteers' => (clone $filtered)->simplePaginate(40)->withQueryString(),
            'matching' => (clone $filtered)->count(),
            'total' => (clone $base)->count(),
            'notContacted' => (clone $base)->whereNull('contacted_at')->count(),
            'newToday' => (clone $base)->where('registered_at', '>=', now()->startOfDay())->count(),
            'roleCounts' => $roleCounts,
            'areaCount' => $inArea->count(),
            'lgas' => (clone $base)->whereNotNull('lga')->distinct()->orderBy('lga')->pluck('lga'),
            'wards' => $filters['lga'] ? (clone $base)->where('lga', $filters['lga'])->whereNotNull('ward')->distinct()->orderBy('ward')->pluck('ward') : collect(),
            'canExport' => $request->user()->can(Permission::EXPORT_DATA),
            'canBroadcast' => $request->user()->can(Permission::MANAGE_BROADCASTS),
        ]);
    }

    public function contacted(Request $request, Volunteer $volunteer): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'contacted' => ['required', 'boolean'],
            'follow_up_note' => ['nullable', 'string', 'max:500'],
        ]);

        $volunteer->forceFill($validated['contacted']
            ? ['contacted_at' => $volunteer->contacted_at ?? now(), 'contacted_by' => $volunteer->contacted_by ?? $request->user()->name, 'follow_up_note' => $validated['follow_up_note'] ?? $volunteer->follow_up_note]
            : ['contacted_at' => null, 'contacted_by' => null])->save();
        Audit::record('volunteer.contacted', ($validated['contacted'] ? 'Marked contacted: ' : 'Marked not contacted: ')."{$volunteer->name} ({$volunteer->reference})");

        $message = $validated['contacted'] ? "Marked {$volunteer->name} as contacted." : "Marked {$volunteer->name} as not contacted yet.";

        return $request->expectsJson() ? response()->json(['message' => $message]) : back()->with('status', $message);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        Audit::record('volunteer.exported', 'Downloaded the volunteers list', array_filter($filters));

        return response()->streamDownload(function () use ($filters) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Name', 'Contact number', 'Dialled from', 'LGA', 'Ward', 'How they can help', 'Professional skills', 'Anything else', 'Agent', 'Signed up', 'Contacted', 'Contacted by', 'Note']);
            foreach ($this->query($filters)->lazy(500) as $volunteer) {
                fputcsv($out, array_map(fn ($value) => is_string($value) && preg_match('/^[=+\-@]/', $value) && ! preg_match('/^\+\d+$/', $value) ? "'".$value : $value, [
                    $volunteer->reference,
                    $volunteer->name,
                    $volunteer->contact_phone,
                    $volunteer->phone_number,
                    $volunteer->lga,
                    $volunteer->ward,
                    implode('; ', $volunteer->roleLabels()),
                    implode('; ', $volunteer->skillLabels()),
                    $volunteer->other,
                    $volunteer->is_agent ? 'yes' : 'no',
                    $volunteer->registered_at ? Time::local($volunteer->registered_at, 'Y-m-d H:i') : '',
                    $volunteer->contacted_at ? Time::local($volunteer->contacted_at, 'Y-m-d H:i') : '',
                    $volunteer->contacted_by,
                    $volunteer->follow_up_note,
                ]));
            }
            fclose($out);
        }, 'volunteers-'.now()->format('Ymd-Hi').'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * @return array{q: string, lga: ?string, ward: ?string, role: ?string, status: string}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'lga' => ['nullable', 'string', 'max:100'],
            'ward' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(array_keys(Volunteer::ROLES))],
            'status' => ['nullable', Rule::in(['all', 'new', 'contacted'])],
        ]);

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'lga' => $validated['lga'] ?? null,
            'ward' => filled($validated['lga'] ?? null) ? ($validated['ward'] ?? null) : null,
            'role' => $validated['role'] ?? null,
            'status' => $validated['status'] ?? 'all',
        ];
    }

    private function query(array $filters): Builder
    {
        return Volunteer::query()
            ->where('rehearsal', Settings::showingRehearsal())
            ->when($filters['lga'], fn (Builder $query, $lga) => $query->where('lga', $lga))
            ->when($filters['ward'], fn (Builder $query, $ward) => $query->where('ward', $ward))
            ->when($filters['role'], fn (Builder $query, $role) => $query->whereJsonContains('roles', $role))
            ->when($filters['status'] === 'new', fn (Builder $query) => $query->whereNull('contacted_at'))
            ->when($filters['status'] === 'contacted', fn (Builder $query) => $query->whereNotNull('contacted_at'))
            ->when($filters['q'] !== '', function (Builder $query) use ($filters) {
                $term = $filters['q'];
                $digits = ltrim(preg_replace('/\D/', '', $term), '0');
                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('reference', 'like', '%'.strtoupper($term).'%')
                    ->orWhere('other', 'like', "%{$term}%")
                    ->when($digits !== '', fn ($query) => $query->orWhere('contact_phone', 'like', "%{$digits}%")->orWhere('phone_number', 'like', "%{$digits}%")));
            })
            ->orderByRaw('case when contacted_at is null then 0 else 1 end')
            ->latest('registered_at')
            ->latest('id');
    }
}
