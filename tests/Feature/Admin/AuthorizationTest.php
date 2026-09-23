<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function adminWithRole(string $role): Admin
    {
        return Admin::factory()->withTwoFactor()->create(['role_id' => Role::firstWhere('name', $role)->id]);
    }

    public function test_menu_access_follows_the_role(): void
    {
        $viewer = $this->adminWithRole('Viewer');

        $this->actingAs($viewer, 'admin')->get('/admin')->assertOk()->assertDontSee('href="'.route('admin.settings.index').'"', false);
        $this->get('/admin/settings')->assertForbidden();
        $this->get('/admin/admins')->assertForbidden();
    }

    public function test_changing_role_access_takes_effect(): void
    {
        $viewer = $this->adminWithRole('Viewer');
        Role::firstWhere('name', 'Viewer')->syncPermissions(['dashboard', 'settings']);

        $this->actingAs($viewer->fresh(), 'admin')->get('/admin/settings')->assertOk();
    }

    public function test_only_super_admins_can_grant_super_admin(): void
    {
        $manager = Admin::factory()->withTwoFactor()->create();
        $manager->role->syncPermissions(['dashboard', 'admins']);
        $super = Role::firstWhere('name', 'Super admin');

        $this->actingAs($manager->fresh(), 'admin')
            ->post('/admin/admins', ['name' => 'Eve', 'email' => 'eve@example.com', 'role_id' => $super->id])
            ->assertSessionHasErrors('role_id');
        $this->assertDatabaseMissing('admins', ['email' => 'eve@example.com']);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $me = $this->adminWithRole('Super admin');

        $this->actingAs($me, 'admin')->post("/admin/admins/{$me->id}/toggle-active")->assertForbidden();
        $this->assertTrue($me->fresh()->is_active);
    }

    public function test_system_role_cannot_be_deleted_and_role_in_use_is_protected(): void
    {
        $me = $this->adminWithRole('Super admin');
        $super = Role::firstWhere('name', 'Super admin');
        $billing = Role::firstWhere('name', 'Billing');
        Admin::factory()->create(['role_id' => $billing->id]);

        $this->actingAs($me, 'admin')->delete("/admin/roles/{$super->id}")->assertForbidden();
        $this->delete("/admin/roles/{$billing->id}")->assertSessionHasErrors('role');
        $this->assertModelExists($billing);
    }

    public function test_changes_are_written_to_the_audit_log(): void
    {
        $me = $this->adminWithRole('Super admin');
        $billing = Role::firstWhere('name', 'Billing');

        $this->actingAs($me, 'admin')->put('/admin/roles/access', ['access' => [$billing->id => ['dashboard']]]);

        $this->assertDatabaseHas('activity_logs', ['action' => 'role.access_changed', 'admin_id' => $me->id]);
    }
}
