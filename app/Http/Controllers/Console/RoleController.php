<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Audit;
use App\Support\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Roles and what each may do. The admin role always has everything; the
 * built-in roles can be edited but not deleted.
 */
class RoleController extends Controller
{
    public function index(): View
    {
        Role::ensureDefaults();

        return view('roles.index', [
            // Admin first, then the built-in roles, then the rest by name.
            'roles' => Role::query()->withCount('users')->get()->sortBy(fn (Role $role) => [! $role->isAdmin(), ! $role->system, $role->name])->values(),
            'groups' => Permission::groups(),
        ]);
    }

    public function create(): View
    {
        return view('roles.edit', ['role' => new Role(['permissions' => []]), 'groups' => Permission::groups()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $key = Str::slug($validated['name'], '_');

        if ($key === '' || Role::query()->where('key', $key)->exists()) {
            return back()->withInput()->withErrors(['name' => 'A role with this name already exists.']);
        }

        $role = Role::create([...$validated, 'key' => Str::limit($key, 40, ''), 'system' => false]);
        Audit::record('role.created', "Created role {$role->name}: ".$this->describe($role));

        return redirect()->route('roles')->with('status', "Created the {$role->name} role. Give it to people on the Users page.");
    }

    public function edit(Role $role): View
    {
        return view('roles.edit', ['role' => $role, 'groups' => Permission::groups()]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $this->validated($request, $role);

        if ($role->isAdmin()) {
            // The admin role keeps every permission; only its description changes.
            $validated['permissions'] = Permission::all();
            $validated['name'] = $role->name;
        }

        $role->update($validated);
        Audit::record('role.updated', "Changed role {$role->name}: ".$this->describe($role));

        return redirect()->route('roles')->with('status', "Saved the {$role->name} role. It applies straight away to everyone who has it.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->system) {
            return back()->with('error', "The {$role->name} role is built in and can't be deleted.");
        }

        if ($role->users()->exists()) {
            return back()->with('error', "Give the people with the {$role->name} role another role first.");
        }

        $role->delete();
        Audit::record('role.deleted', "Deleted role {$role->name}");

        return redirect()->route('roles')->with('status', "Deleted the {$role->name} role.");
    }

    /**
     * @return array{name: string, description: ?string, permissions: list<string>}
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('roles', 'name')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:300'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permission::all())],
        ]);

        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'permissions' => array_values(array_unique($validated['permissions'] ?? [])),
        ];
    }

    private function describe(Role $role): string
    {
        $grants = $role->grants();

        return $grants ? implode(', ', array_map(Permission::label(...), $grants)) : 'no permissions';
    }
}
