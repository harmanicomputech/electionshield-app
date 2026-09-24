<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OptOut extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['phone', 'channel', 'source'];

    public static function record(string $phone, string $channel, string $source): void
    {
        static::query()->firstOrCreate(['phone' => $phone, 'channel' => $channel], ['source' => $source]);
    }
}
