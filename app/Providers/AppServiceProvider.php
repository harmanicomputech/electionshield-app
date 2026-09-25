<?php

namespace App\Providers;

use App\Enums\ResultStatus;
use App\Models\Incident;
use App\Models\Result;
use App\Models\SyncState;
use App\Models\WebhookEvent;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        View::composer('layouts.app', function ($view) {
            $view->with([
                'showingRehearsal' => Settings::showingRehearsal(),
                'lastData' => $this->lastDataReceived(),
                'urgentOpen' => $this->urgentOpenIncidents(),
                'pendingCorrections' => $this->pendingCorrections(),
            ]);
        });
    }

    /**
     * Urgent incidents nobody has acknowledged yet (the Incidents tab badge).
     */
    private function urgentOpenIncidents(): int
    {
        try {
            return auth()->check()
                ? Incident::query()->where('rehearsal', Settings::showingRehearsal())->where('urgent', true)->withResponseStatus(Incident::OPEN)->count()
                : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Corrections waiting for review (the sidebar badge).
     */
    private function pendingCorrections(): int
    {
        try {
            return auth()->check()
                ? Result::query()->where('rehearsal', Settings::showingRehearsal())->where('status', ResultStatus::Pending)->whereNotNull('corrects_reference')->count()
                : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * When the USSD service last gave us anything (a webhook or a sync).
     */
    private function lastDataReceived(): ?Carbon
    {
        try {
            $times = array_filter([
                WebhookEvent::query()->max('received_at'),
                SyncState::query()->max('last_success_at'),
            ]);

            return $times ? Carbon::parse(max($times)) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
