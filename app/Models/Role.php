<?php

namespace App\Models;

use App\Support\Permission;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;

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

    /** @var array<string, ?Role> */
    private static array $byKey = [];

    private static ?int $cachedFor = null;

    /**
     * The role with this key. Before the database has been updated (new
     * files uploaded, Update database not yet pressed), the built-in roles
     * are used, so people can still log in and reach the System page.
     */
    public static function forKey(?string $key): ?self
    {
        if ($key === null) {
            return null;
        }

        // One lookup per request (a new app instance starts afresh).
        if (self::$cachedFor !== spl_object_id(app())) {
            self::$byKey = [];
            self::$cachedFor = spl_object_id(app());
        }

        if (! array_key_exists($key, self::$byKey)) {
            try {
                self::$byKey[$key] = static::query()->where('key', $key)->first();
            } catch (QueryException) {
                $default = Permission::defaultRoles()[$key] ?? null;
                self::$byKey[$key] = $default ? new self([...$default, 'key' => $key, 'system' => true]) : null;
            }
        }

        return self::$byKey[$key];
    }

    public static function forgetCache(): void
    {
        self::$byKey = [];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forgetCache());
        static::deleted(fn () => self::forgetCache());
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
