<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Role;
use App\Models\User;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_roles_and_what_they_allow(): void
    {
        $this->assertEqualsCanonicalizing(['admin', 'coordinator', 'observer', 'agent'], Role::query()->pluck('key')->all());

        $admin = User::factory()->admin()->create();
        $coordinator = User::factory()->create();
        $observer = User::factory()->role('observer')->create();

        foreach (Permission::all() as $permission) {
            $this->assertTrue($admin->can($permission), $permission);
        }

        $this->assertTrue($coordinator->can(Permission::RESPOND_INCIDENTS));
        $this->assertFalse($coordinator->can(Permission::MANAGE_USERS));
        $this->assertFalse($coordinator->can(Permission::MANAGE_SYSTEM));
        $this->assertTrue($observer->can(Permission::VIEW_DASHBOARDS));
        $this->assertFalse($observer->can(Permission::RESPOND_INCIDENTS));
        $this->assertFalse($observer->can(Permission::VIEW_AGENTS));
    }

    public function test_coordinators_cannot_reach_users_roles_or_system(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['/users', '/roles', '/system', '/audit', '/broadcasts'] as $page) {
            $this->get($page)->assertForbidden();
        }

        $this->get('/')->assertOk()->assertDontSee('href="'.url('/users').'"', false)->assertDontSee('>Roles<', false);
    }

    public function test_observers_see_dashboards_but_cannot_act_or_see_phone_numbers(): void
    {
        $this->actingAs(User::factory()->role('observer')->create());

        $this->get('/')->assertOk();
        $this->get('/incidents')->assertOk();
        $this->get('/agents')->assertForbidden();
        Incident::query()->create(['reference' => 'IN1', 'polling_unit_code' => '21202633007', 'type' => 'violence', 'urgent' => true, 'agent_phone' => '+2348012345678', 'reported_at' => now()]);
        $this->get('/incidents')->assertSee('IN1')->assertDontSee('+2348012345678')->assertDontSee('Acknowledge</button>', false);
        $this->post('/incidents/IN1/acknowledge')->assertForbidden();
        $this->post('/corrections/RS1/approve')->assertForbidden();
    }

    public function test_admins_edit_a_role_and_it_applies_straight_away(): void
    {
        $observer = User::factory()->role('observer')->create();
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/roles')->assertOk()->assertSee('Observer')->assertSee('See the dashboards');
        $this->put('/roles/'.Role::query()->where('key', 'observer')->value('id'), [
            'name' => 'Observer',
            'permissions' => [Permission::VIEW_DASHBOARDS, Permission::VIEW_INCIDENTS, Permission::VIEW_AGENTS],
        ])->assertRedirect('/roles');

        $this->assertTrue($observer->fresh()->can(Permission::VIEW_AGENTS));
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.updated']);
    }

    public function test_custom_roles_are_created_given_and_deleted(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/roles', ['name' => 'LGA supervisor', 'permissions' => [Permission::VIEW_DASHBOARDS, Permission::RESPOND_INCIDENTS, 'not_a_permission']])
            ->assertSessionHasErrors('permissions.2');
        $this->post('/roles', ['name' => 'LGA supervisor', 'permissions' => [Permission::VIEW_DASHBOARDS, Permission::RESPOND_INCIDENTS]])->assertRedirect('/roles');

        $role = Role::query()->where('key', 'lga_supervisor')->firstOrFail();
        $this->post('/users', ['name' => 'Sup', 'email' => 'sup@example.com', 'role' => 'lga_supervisor', 'password' => 'long-enough-password'])->assertSessionHasNoErrors();
        $supervisor = User::query()->where('email', 'sup@example.com')->firstOrFail();
        $this->assertTrue($supervisor->can(Permission::RESPOND_INCIDENTS));
        $this->assertFalse($supervisor->can(Permission::REVIEW_CORRECTIONS));

        $this->delete('/roles/'.$role->id)->assertSessionHas('error');
        $supervisor->delete();
        $this->delete('/roles/'.$role->id)->assertRedirect('/roles');
        $this->assertModelMissing($role);

        $this->delete('/roles/'.Role::query()->where('key', 'observer')->value('id'))->assertSessionHas('error');
    }

    public function test_the_admin_role_keeps_everything_and_one_admin_always_remains(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->put('/roles/'.Role::query()->where('key', 'admin')->value('id'), ['name' => 'Admin', 'permissions' => []])->assertRedirect('/roles');
        $this->assertTrue($admin->fresh()->can(Permission::MANAGE_SYSTEM));

        $this->put("/users/{$admin->id}", ['role' => 'coordinator'])->assertSessionHas('error');
        $this->post('/users', ['name' => 'Agent?', 'email' => 'a@example.com', 'role' => 'agent', 'password' => 'long-enough-password'])->assertSessionHasErrors('role');
    }
}
