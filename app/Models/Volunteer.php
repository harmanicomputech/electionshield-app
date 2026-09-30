<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A "How can you help?" sign-up from the USSD service (anyone can dial).
 * One per contact number there (one phone can sign up several people); the USSD service sends it again when it changes.
 */
class Volunteer extends Model
{
    /** How they can help: USSD key => label (menu order). */
    public const ROLES = [
        'canvass' => 'Canvass in my ward',
        'pu_agent' => 'Serve as a polling unit agent',
        'share' => 'Share on WhatsApp and social media',
        'women' => 'Mobilise women',
        'youth' => 'Mobilise youth',
        'transport' => 'Transport and logistics',
        'professional' => 'Professional skills (legal, media, medical, IT)',
        'other' => 'Anything else',
    ];

    public const SKILLS = ['legal' => 'Legal', 'media' => 'Media', 'medical' => 'Medical', 'it' => 'IT', 'other' => 'Other'];

    protected $fillable = [
        'reference', 'name', 'phone_number', 'contact_phone', 'lga', 'ward', 'roles', 'skills', 'other',
        'is_agent', 'channel', 'registered_at', 'ussd_updated_at', 'rehearsal',
    ];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'skills' => 'array',
            'is_agent' => 'boolean',
            'rehearsal' => 'boolean',
            'registered_at' => 'datetime',
            'ussd_updated_at' => 'datetime',
            'contacted_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function roleLabels(): array
    {
        return array_values(array_map(fn (string $role) => self::ROLES[$role] ?? $role, $this->roles ?? []));
    }

    /**
     * @return list<string>
     */
    public function skillLabels(): array
    {
        return array_values(array_map(fn (string $skill) => self::SKILLS[$skill] ?? $skill, $this->skills ?? []));
    }

    /**
     * "Abakaliki Ward 03" → "Ward 03" when the LGA is shown next to it.
     */
    public function wardLabel(): string
    {
        return $this->lga && str_starts_with((string) $this->ward, $this->lga.' ') ? substr($this->ward, strlen($this->lga) + 1) : (string) $this->ward;
    }

    /**
     * The number for WhatsApp links: digits only, with the country code.
     */
    public function whatsappNumber(): string
    {
        return ltrim((string) preg_replace('/\D/', '', $this->contact_phone), '0');
    }
}
