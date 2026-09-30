<?php

namespace App\Models;

use App\Services\PollingUnitImporter;
use Illuminate\Database\Eloquent\Model;

class PollingUnit extends Model
{
    protected $fillable = ['code', 'name', 'ward', 'lga', 'registered_voters', 'latitude', 'longitude', 'location_source'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float'];
    }

    /**
     * INEC codes like 11/01/01/007 (state/LGA/ward/PU) are stored as digits: 110101007.
     */
    public static function normalizeCode(string $code): string
    {
        return preg_replace('/\D/', '', $code) ?? '';
    }

    /**
     * 11/01/01/007 for a stored code of 110101007.
     */
    public function inecCode(): string
    {
        return preg_match('/^(\d{2})(\d{2})(\d{2})(\d{3})$/', (string) $this->code, $part)
            ? "{$part[1]}/{$part[2]}/{$part[3]}/{$part[4]}"
            : (string) $this->code;
    }

    /**
     * Whether the PU's position is only INEC's approximate one.
     */
    public function hasApproximateLocation(): bool
    {
        return $this->location_source === PollingUnitImporter::INEC_LOCATION;
    }
}
