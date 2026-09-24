<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records who did what. Recording never breaks the action being recorded.
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function record(string $action, string $description, array $details = [], ?string $actor = null): void
    {
        try {
            $user = auth()->user();

            AuditLog::create([
                'user_id' => $user?->id,
                'user_name' => $actor ?? $user?->name ?? 'System',
                'action' => $action,
                'description' => Str::limit($description, 490),
                'details' => $details ?: null,
                'ip_address' => app()->runningInConsole() ? null : request()->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
