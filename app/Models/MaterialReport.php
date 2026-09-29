<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialReport extends Model
{
    protected $fillable = [
        'ussd_id', 'polling_unit_code', 'lga', 'ward', 'status', 'status_label',
        'agent_name', 'agent_phone', 'reported_at', 'rehearsal', 'channel',
    ];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime', 'rehearsal' => 'boolean'];
    }

    /** Statuses that need a photo or video from the web app. */
    public const NEEDS_EVIDENCE = ['arrived', 'incomplete'];

    /**
     * The key its photos and videos are filed under (materials reports have no USSD reference).
     */
    public static function referenceFor(int $ussdId): string
    {
        return 'MAT-'.$ussdId;
    }

    public function reference(): string
    {
        return self::referenceFor((int) $this->ussd_id);
    }

    public function channelLabel(): string
    {
        return $this->channel === 'web' ? 'web app' : 'USSD';
    }
}
