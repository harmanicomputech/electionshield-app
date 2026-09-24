<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PollingUnit extends Model
{
    protected $fillable = ['code', 'name', 'ward', 'lga', 'registered_voters'];

    /**
     * INEC codes like EB/212/02633/007 are stored as digits: 21202633007.
     */
    public static function normalizeCode(string $code): string
    {
        return preg_replace('/\D/', '', $code) ?? '';
    }

    /**
     * EB/212/02633/007 for a stored code of 21202633007.
     */
    public function inecCode(): string
    {
        return strlen($this->code) === 11
            ? 'EB/'.substr($this->code, 0, 3).'/'.substr($this->code, 3, 5).'/'.substr($this->code, 8)
            : $this->code;
    }
}
