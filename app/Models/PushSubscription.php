<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    public const TOPICS = [
        'urgent_incidents' => 'Urgent incidents (violence, vote suppression, malpractice)',
        'corrections' => 'Corrections waiting for review',
    ];

    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'content_encoding', 'topics', 'device'];

    protected function casts(): array
    {
        return ['topics' => 'array', 'last_sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function scopeForTopic(Builder $query, string $topic): void
    {
        $query->whereJsonContains('topics', $topic);
    }
}
