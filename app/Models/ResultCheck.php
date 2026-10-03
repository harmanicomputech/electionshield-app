<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The automatic checks on one agent result: the rule flags when it arrived
 * (they are worked out again live on the Result checks page) and the AI
 * reading of its EC8A photo. A person marks it reviewed.
 */
class ResultCheck extends Model
{
    public const SERIOUS = 'serious';

    public const REVIEW = 'review';

    /** The photo's figures agree with the agent's. */
    public const PHOTO_MATCHES = 'matches';

    public const PHOTO_MISMATCH = 'mismatch';

    /** The AI could not read the photo (not an EC8A, or too unclear). */
    public const PHOTO_UNREADABLE = 'unreadable';

    protected $fillable = [
        'result_reference', 'level', 'flags', 'photo_id', 'photo_status', 'photo_reading', 'photo_differences',
        'photo_error', 'photo_attempts', 'photo_checked_at', 'reviewed_at', 'reviewed_by', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'flags' => 'array',
            'photo_reading' => 'array',
            'photo_differences' => 'array',
            'photo_checked_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'result_reference', 'reference');
    }
}
