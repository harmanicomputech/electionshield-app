<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Coordinator = 'coordinator';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
