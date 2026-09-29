<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a person was when they used the app (roles with "Location is
 * recorded"): status ok with coordinates, or denied / unavailable.
 */
class UserLocation extends Model
{
    public const OK = 'ok';

    public const DENIED = 'denied';

    public const UNAVAILABLE = 'unavailable';

    protected $fillable = ['user_id', 'status', 'latitude', 'longitude', 'accuracy', 'action', 'located_at'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'accuracy' => 'float', 'located_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
