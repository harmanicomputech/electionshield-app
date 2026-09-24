<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResultVote extends Model
{
    public $timestamps = false;

    protected $fillable = ['result_id', 'party', 'votes'];
}
