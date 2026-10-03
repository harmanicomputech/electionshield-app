<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A short written summary of election day so far, written by AI from the
 * facts it was given (kept with it, so every point can be checked).
 */
class SituationBrief extends Model
{
    protected $fillable = ['rehearsal', 'headline', 'points', 'actions', 'facts', 'written_by'];

    protected function casts(): array
    {
        return ['rehearsal' => 'boolean', 'points' => 'array', 'actions' => 'array', 'facts' => 'array'];
    }
}
