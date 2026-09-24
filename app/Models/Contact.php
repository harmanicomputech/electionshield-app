<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    public const TYPES = ['supporter' => 'Supporter', 'coordinator' => 'Coordinator', 'other' => 'Other'];

    protected $fillable = ['name', 'phone', 'type', 'lga', 'ward', 'sms_opt_in_at', 'whatsapp_opt_in_at', 'source'];

    protected function casts(): array
    {
        return ['sms_opt_in_at' => 'datetime', 'whatsapp_opt_in_at' => 'datetime'];
    }
}
