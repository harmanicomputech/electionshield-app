<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialReport extends Model
{
    protected $fillable = [
        'ussd_id', 'polling_unit_code', 'lga', 'ward', 'status', 'status_label',
        'agent_name', 'agent_phone', 'reported_at', 'rehearsal',
    ];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime', 'rehearsal' => 'boolean'];
    }
}
