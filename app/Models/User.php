<?php

namespace App\Models;

use App\Support\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'password', 'role', 'lga'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function roleModel(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role', 'key');
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::ADMIN;
    }

    public function isAgent(): bool
    {
        return $this->role === Role::AGENT;
    }

    public function roleName(): string
    {
        return Role::forKey($this->role)?->name ?? ucfirst((string) $this->role);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->isAdmin() || (Role::forKey($this->role)?->allows($permission) ?? false);
    }

    /**
     * The agent record for an agent's account (matched by phone number).
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'phone', 'phone_number');
    }

    /**
     * Whether the app records this person's location (admins never).
     */
    public function sharesLocation(): bool
    {
        return ! $this->isAdmin() && $this->hasPermission(Permission::SHARE_LOCATION);
    }

    /**
     * Where to land after signing in.
     */
    public function homeRoute(): string
    {
        return ! $this->hasPermission(Permission::VIEW_DASHBOARDS) && $this->hasPermission(Permission::SUBMIT_FIELD_REPORTS) && filled($this->phone) ? 'field' : 'dashboard';
    }
}
