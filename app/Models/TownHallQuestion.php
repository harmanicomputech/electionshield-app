<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TownHallQuestion extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const ANSWERED = 'answered';

    protected $fillable = ['town_hall_session_id', 'name', 'lga', 'body', 'status', 'ip_hash'];

    protected function casts(): array
    {
        return ['on_air_at' => 'datetime', 'moderated_at' => 'datetime', 'answered_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TownHallSession::class, 'town_hall_session_id');
    }

    /**
     * What the public sees: approved and answered questions only.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name ?: 'A voter',
            'lga' => $this->lga,
            'body' => $this->body,
            'answered' => $this->status === self::ANSWERED,
            'on_air' => $this->on_air_at !== null,
        ];
    }
}
