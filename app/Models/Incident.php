<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Incident extends Model
{
    public const OPEN = 'open';

    public const ACKNOWLEDGED = 'acknowledged';

    public const RESOLVED = 'resolved';

    protected $fillable = [
        'reference', 'polling_unit_code', 'lga', 'ward', 'type', 'type_label', 'urgent',
        'note', 'agent_name', 'agent_phone', 'reported_at', 'rehearsal',
    ];

    protected function casts(): array
    {
        return [
            'urgent' => 'boolean',
            'reported_at' => 'datetime',
            'rehearsal' => 'boolean',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class, 'polling_unit_code', 'code');
    }

    /**
     * open → acknowledged (someone is on it) → resolved.
     */
    public function responseStatus(): string
    {
        return match (true) {
            $this->resolved_at !== null => self::RESOLVED,
            $this->acknowledged_at !== null => self::ACKNOWLEDGED,
            default => self::OPEN,
        };
    }

    public function label(): string
    {
        return $this->type_label ?: ucfirst(str_replace('_', ' ', $this->type));
    }

    public function scopeUnresolved(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function scopeWithResponseStatus(Builder $query, string $status): void
    {
        match ($status) {
            self::OPEN => $query->whereNull('acknowledged_at')->whereNull('resolved_at'),
            self::ACKNOWLEDGED => $query->whereNotNull('acknowledged_at')->whereNull('resolved_at'),
            self::RESOLVED => $query->whereNotNull('resolved_at'),
            default => null,
        };
    }
}
