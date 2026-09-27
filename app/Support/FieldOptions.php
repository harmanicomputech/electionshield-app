<?php

namespace App\Support;

/**
 * The choices on the agent pages, matching the USSD service's menus.
 */
final class FieldOptions
{
    /**
     * @return array<string, string> USSD incident type => label
     */
    public static function incidentTypes(): array
    {
        return [
            'violence' => 'Violence',
            'vote_suppression' => 'Vote suppression',
            'malpractice' => 'Malpractice',
            'vote_buying' => 'Vote buying',
            'delay' => 'Delay',
            'other' => 'Other',
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}> status => [label, badge class]
     */
    public static function materialStatuses(): array
    {
        return [
            'arrived' => ['Arrived (complete)', 'good'],
            'incomplete' => ['Arrived (incomplete)', 'warn'],
            'not_arrived' => ['Not arrived yet', 'bad'],
        ];
    }
}
