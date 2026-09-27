<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Remind me later" on a pop-up, per person: the report pops up again for
 * them once `until` has passed, unless someone has dealt with it.
 */
class AlertSnooze extends Model
{
    protected $fillable = ['user_id', 'reference', 'until'];

    protected function casts(): array
    {
        return ['until' => 'datetime'];
    }
}
