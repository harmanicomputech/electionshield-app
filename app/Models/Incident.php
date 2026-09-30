<?php

namespace App\Models;

use App\Services\PushAlerts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Incident extends Model
{
    public const OPEN = 'open';

    public const ACKNOWLEDGED = 'acknowledged';

    public const RESOLVED = 'resolved';

    /** Reported by a member of the public (anyone who dialled the USSD code): unverified. */
    public const SOURCE_PUBLIC = 'public';

    public const SOURCE_AGENT = 'agent';

    protected $fillable = [
        'reference', 'polling_unit_code', 'lga', 'ward', 'type', 'type_label', 'urgent',
        'note', 'agent_name', 'agent_phone', 'reported_at', 'rehearsal', 'channel',
        'source', 'reporter_phone',
    ];

    public function channelLabel(): string
    {
        return $this->channel === 'web' ? 'Web app' : 'USSD';
    }

    public function isPublic(): bool
    {
        return $this->source === self::SOURCE_PUBLIC;
    }

    /**
     * Who reported it, for screens: the agent, or "Member of the public".
     */
    public function reporterName(): string
    {
        return $this->isPublic() ? 'Member of the public' : ($this->agent_name ?: 'An agent');
    }

    public function reporterPhone(): ?string
    {
        return $this->isPublic() ? $this->reporter_phone : $this->agent_phone;
    }

    /**
     * Where, for screens: the PU name, or the ward and LGA a member of the public picked.
     */
    public function placeLabel(): string
    {
        if ($this->polling_unit_code) {
            return $this->pollingUnit?->name ?? 'PU '.$this->polling_unit_code;
        }

        return trim(($this->ward ?? '').($this->lga ? ', '.$this->lga : ''), ', ') ?: 'Place not given';
    }

    protected static function booted(): void
    {
        static::created(fn (Incident $incident) => PushAlerts::incidentCreated($incident));
    }

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
