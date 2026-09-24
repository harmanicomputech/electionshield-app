<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $fillable = [
        'reference', 'polling_unit_code', 'lga', 'ward', 'type', 'type_label', 'urgent',
        'note', 'agent_name', 'agent_phone', 'reported_at', 'rehearsal',
    ];

    protected function casts(): array
    {
        return ['urgent' => 'boolean', 'reported_at' => 'datetime', 'rehearsal' => 'boolean'];
    }
}
