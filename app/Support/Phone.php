<?php

namespace App\Support;

/**
 * Nigerian phone numbers in E.164: 08031234567, 2348031234567 and
 * +234 803 123 4567 all become +2348031234567.
 */
class Phone
{
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        $digits = match (true) {
            str_starts_with($digits, '234') && strlen($digits) === 13 => $digits,
            str_starts_with($digits, '0') && strlen($digits) === 11 => '234'.substr($digits, 1),
            strlen($digits) === 10 && in_array($digits[0], ['7', '8', '9'], true) => '234'.$digits,
            default => null,
        };

        return $digits === null ? null : '+'.$digits;
    }
}
