<?php

namespace App\Enums;

/**
 * pending → accepted | rejected, and accepted → superseded. Events can arrive
 * out of order, so a result never moves back to an earlier stage.
 */
enum ResultStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function stage(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Accepted => 1,
            self::Rejected, self::Superseded => 2,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
