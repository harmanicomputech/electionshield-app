<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Presence extends Model
{
    protected $fillable = [
        'ussd_id', 'polling_unit_code', 'lga', 'ward', 'agent_name', 'agent_phone', 'confirmed_at', 'rehearsal', 'channel',
        'latitude', 'longitude', 'location_accuracy', 'located_at', 'location_reviewed_at', 'location_reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'rehearsal' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'location_accuracy' => 'float',
            'located_at' => 'datetime',
            'location_reviewed_at' => 'datetime',
        ];
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class, 'polling_unit_code', 'code');
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
