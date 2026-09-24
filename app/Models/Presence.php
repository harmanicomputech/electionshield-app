<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Presence extends Model
{
    protected $fillable = [
        'ussd_id', 'polling_unit_code', 'lga', 'ward', 'agent_name', 'agent_phone', 'confirmed_at', 'rehearsal',
    ];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'rehearsal' => 'boolean'];
    }
}
