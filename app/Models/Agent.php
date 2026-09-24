<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Agent extends Model
{
    protected $fillable = ['ussd_id', 'name', 'phone_number', 'polling_unit_code', 'locked', 'last_seen_at'];

    protected function casts(): array
    {
        return ['locked' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class, 'polling_unit_code', 'code');
    }
}
