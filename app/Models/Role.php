<?php

namespace App\Models;

use App\Support\Permission;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role: a name and the permissions (App\Support\Permission) it grants.
 * "admin" always has every permission; "admin" and "agent" can't be deleted.
 */
#[Fillable(['key', 'name', 'description', 'permissions', 'system'])]
class Role extends Model
{
    public const ADMIN = 'admin';

    public const AGENT = 'agent';

    protected function casts(): array
    {
        return ['permissions' => 'array', 'system' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'key');
    }

    public function isAdmin(): bool
    {
        return $this->key === self::ADMIN;
    }

    /**
     * @return list<string>
     */
    public function grants(): array
    {
        return $this->isAdmin() ? Permission::all() : array_values(array_intersect(Permission::all(), $this->permissions ?? []));
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->grants(), true);
    }

    /**
     * Create the default roles that don't exist yet (fresh installs and upgrades).
     */
    public static function ensureDefaults(): void
    {
        foreach (Permission::defaultRoles() as $key => $role) {
            static::query()->firstOrCreate(['key' => $key], [...$role, 'system' => true]);
        }
    }
}
