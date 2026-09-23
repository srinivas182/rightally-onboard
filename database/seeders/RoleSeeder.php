<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * The Super admin system role plus three starter roles from the approved
 * prototype. Starter roles can be edited or deleted in the admin.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(
            ['name' => config('rightally.super_admin_role')],
            ['description' => 'Full access to everything', 'is_system' => true],
        );

        $starter = [
            'Billing' => ['dashboard', 'customers', 'contracts', 'invoices', 'reports', 'email_templates'],
            'Sales' => ['dashboard', 'customers', 'contracts', 'coupons', 'quotes', 'reports'],
            'Viewer' => ['dashboard', 'customers'],
        ];

        foreach ($starter as $name => $menus) {
            $role = Role::firstOrCreate(['name' => $name]);
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($menus);
            }
        }
    }
}
