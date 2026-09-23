<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Role;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the first super admin from SUPER_ADMIN_* in .env.
 * Two-factor setup is forced at first sign-in.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower((string) config('rightally.first_super_admin.email'));
        $password = (string) config('rightally.first_super_admin.password');

        if (Admin::where('email', $email)->exists()) {
            return;
        }
        if (! $email || strlen($password) < 12) {
            throw new RuntimeException('Set SUPER_ADMIN_EMAIL and a SUPER_ADMIN_PASSWORD of at least 12 characters in .env, then run the seeder again.');
        }

        Admin::create([
            'role_id' => Role::where('name', config('rightally.super_admin_role'))->value('id'),
            'name' => (string) config('rightally.first_super_admin.name'),
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);

        $this->command?->info("Super admin {$email} created. Remove SUPER_ADMIN_PASSWORD from .env now.");
    }
}
