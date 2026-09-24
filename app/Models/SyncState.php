<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncState extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'resource';

    protected $keyType = 'string';

    protected $fillable = ['resource', 'synced_until', 'last_run_at', 'last_success_at', 'last_count', 'last_error'];

    protected function casts(): array
    {
        return ['synced_until' => 'datetime', 'last_run_at' => 'datetime', 'last_success_at' => 'datetime'];
    }
}
