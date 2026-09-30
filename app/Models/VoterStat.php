<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One sourced voter figure: e.g. "Ikwo LGA, PVC collection, uncollected =
 * 18,978 (INEC, Feb 2023)". `area` is '' for Nigeria and the state, the LGA
 * name, "LGA|Ward", or a PU code.
 */
class VoterStat extends Model
{
    public const LEVELS = ['national' => 'Nigeria', 'state' => 'State', 'lga' => 'LGA', 'ward' => 'Ward', 'pu' => 'Polling unit'];

    /**
     * What can be recorded: dimension => [label, categories in display order (key => label), hint].
     * Other categories are allowed and shown with their own name.
     */
    public const DIMENSIONS = [
        'registered' => ['Registered voters', ['total' => 'Registered voters'], 'INEC\'s total for the area'],
        'pvc' => ['PVC collection', ['collected' => 'PVCs collected', 'uncollected' => 'PVCs not collected'], 'People who can\'t vote until they collect their card'],
        'first_time' => ['First-time voters', ['new' => 'Newly registered (CVR)'], 'New registrants since the last election'],
        'gender' => ['Gender', ['male' => 'Men', 'female' => 'Women'], ''],
        'age' => ['Age group', ['18-34' => '18–34 (youth)', '35-49' => '35–49', '50-69' => '50–69', '70+' => '70 and above'], 'INEC\'s age groups'],
        'occupation' => ['Occupation', [
            'student' => 'Students', 'farmer_fisher' => 'Farmers & fishermen', 'housewife' => 'Housewives', 'trader' => 'Traders & business',
            'artisan' => 'Artisans', 'civil_servant' => 'Civil & public servants', 'professional' => 'Professionals', 'unemployed' => 'Unemployed', 'other' => 'Others',
        ], 'As declared at registration'],
        'disability' => ['Voters with disability', [], 'By type, as INEC records it'],
    ];

    protected $fillable = ['level', 'area', 'dimension', 'category', 'count', 'percent', 'source', 'source_url', 'as_of', 'note', 'added_by'];

    protected function casts(): array
    {
        return ['count' => 'integer', 'percent' => 'float', 'as_of' => 'date'];
    }

    public static function categoryLabel(string $dimension, string $category): string
    {
        return self::DIMENSIONS[$dimension][1][$category] ?? ucfirst(str_replace('_', ' ', $category));
    }

    public static function areaKey(string $level, ?string $lga = null, ?string $ward = null, ?string $code = null): string
    {
        return match ($level) {
            'lga' => (string) $lga,
            'ward' => $lga.'|'.$ward,
            'pu' => (string) $code,
            default => '',
        };
    }
}
