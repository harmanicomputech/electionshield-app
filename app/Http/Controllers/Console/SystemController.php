<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\PollingUnit;
use App\Models\PushSubscription;
use App\Models\Result;
use App\Models\SyncState;
use App\Models\WebhookEvent;
use App\Services\PollingUnitImporter;
use App\Services\PushNotifier;
use App\Services\UssdIngestor;
use App\Services\UssdSync;
use App\Support\Audit;
use App\Support\Settings;
use App\Support\Time;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * The connection to the USSD service and the operator tasks that would
 * otherwise need a terminal.
 */
class SystemController extends Controller
{
    public function show(UssdSync $sync, PushNotifier $notifier): View
    {
        $heartbeat = Settings::get('scheduler_heartbeat');

        return view('system.show', [
            'webhookUrl' => url('/api/ussd-events'),
            'webhookConfigured' => filled(config('services.ussd.webhook_token')) && filled(config('services.ussd.webhook_secret')),
            'apiConfigured' => $sync->enabled(),
            'apiUrl' => config('services.ussd.api_url'),
            'lastEvent' => WebhookEvent::query()->latest('received_at')->first(),
            'failedEvents' => WebhookEvent::query()->whereNull('processed_at')->whereNotNull('error')->latest('received_at')->limit(20)->get(),
            'states' => SyncState::query()->get()->keyBy('resource'),
            'resources' => UssdSync::RESOURCES,
            'heartbeat' => $heartbeat ? Time::parse($heartbeat) : null,
            'counts' => [
                'Polling units' => PollingUnit::query()->count(),
                'Results (real)' => Result::query()->where('rehearsal', false)->count(),
                'Results (rehearsal)' => Result::query()->where('rehearsal', true)->count(),
                'Incidents' => Incident::query()->count(),
                'Webhook events' => WebhookEvent::query()->count(),
            ],
            'showingRehearsal' => Settings::showingRehearsal(),
            'pushConfigured' => $notifier->configured(),
            'register' => [
                'units' => PollingUnit::query()->count(),
                'lgas' => PollingUnit::query()->distinct()->count('lga'),
                'wards' => PollingUnit::query()->select('lga', 'ward')->distinct()->get()->count(),
                'registered' => (int) PollingUnit::query()->sum('registered_voters'),
            ],
            'pushDevices' => PushSubscription::query()->count(),
        ]);
    }

    /**
     * Pull from the read API now. Runs in the request, as the host has no
     * shell; a full import of the whole register takes well under a minute.
     */
    public function sync(Request $request, UssdSync $sync): RedirectResponse
    {
        $full = $request->boolean('full');
        @set_time_limit(300);

        try {
            $report = $sync->run($full);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $errors = array_filter(array_column($report, 'error'));
        $summary = collect($report)->map(fn (array $row, string $resource) => "{$resource}: {$row['count']}")->implode(', ');
        Audit::record('sync.run', ($full ? 'Full import' : 'Sync').' from the USSD service: '.$summary);

        return back()->with($errors ? 'error' : 'status', ($full ? 'Full import' : 'Sync').' finished. '.$summary.($errors ? '. Errors: '.implode(' | ', $errors) : '.'));
    }

    /**
     * Apply stored webhook events that failed (after a fix, say).
     */
    public function reprocess(UssdIngestor $ingestor): RedirectResponse
    {
        $done = 0;
        $failed = 0;

        WebhookEvent::query()->whereNull('processed_at')->orderBy('id')->each(function (WebhookEvent $event) use ($ingestor, &$done, &$failed) {
            try {
                $known = DB::transaction(fn () => $ingestor->applyEvent($event->event, (array) ($event->payload['data'] ?? [])));
                $event->forceFill(['processed_at' => now(), 'error' => $known ? null : 'Unknown event type; stored only.'])->save();
                $done++;
            } catch (Throwable $e) {
                $event->forceFill(['error' => Str::limit($e->getMessage(), 1000)])->save();
                $failed++;
            }
        });

        Audit::record('webhook.reprocess', "Reprocessed webhook events: {$done} applied, {$failed} still failing");

        return back()->with($failed ? 'error' : 'status', "{$done} events applied, {$failed} still failing.");
    }

    /**
     * Import the PU register: the bundled file, or an uploaded CSV.
     */
    public function importRegister(Request $request, PollingUnitImporter $importer): RedirectResponse
    {
        $request->validate(['file' => ['nullable', 'file', 'max:4096', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel']]);
        $upload = $request->file('file');

        try {
            $result = $importer->import($upload ? $upload->getRealPath() : PollingUnitImporter::bundledPath());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $source = $upload ? $upload->getClientOriginalName() : 'the bundled Ebonyi register';
        Audit::record('system.pu_import', "Imported polling units from {$source}: {$result['created']} added, {$result['updated']} updated".($result['errors'] ? ', '.count($result['errors']).' rows skipped' : ''));

        return back()
            ->with($result['errors'] ? 'error' : 'status', "Polling units from {$source}: {$result['created']} added, {$result['updated']} updated.".($result['errors'] ? ' Some rows were skipped: '.implode('; ', array_slice($result['errors'], 0, 5)) : ''));
    }

    /**
     * Run pending migrations after uploading a new version.
     */
    public function migrate(): RedirectResponse
    {
        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Could not update the database: '.$e->getMessage());
        }

        Audit::record('system.migrate', 'Updated the database');

        return back()->with('status', 'The database is up to date.');
    }

    /**
     * Create the Web Push (VAPID) keys, once.
     */
    public function pushKeys(PushNotifier $notifier): RedirectResponse
    {
        if (! $notifier->generateKeys()) {
            return back()->with('error', 'Notification keys already exist. Changing them would stop every device\'s notifications.');
        }

        Audit::record('system.push_keys', 'Set up notification (VAPID) keys');

        return back()->with('status', 'Notifications are set up. Each person can now turn them on under More → Notifications.');
    }

    public function dataView(Request $request): RedirectResponse
    {
        $view = $request->validate(['view' => ['required', 'in:real,rehearsal']])['view'];
        Settings::set('data_view', $view);
        Audit::record('settings.data_view', $view === 'rehearsal' ? 'Dashboards now show rehearsal data' : 'Dashboards now show real results');

        return back()->with('status', $view === 'rehearsal' ? 'Dashboards now show rehearsal data.' : 'Dashboards now show real results.');
    }
}
