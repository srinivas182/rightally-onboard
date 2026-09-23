<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<Admin> */
class AdminFactory extends Factory
{
    protected $model = Admin::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
        ];
    }

    public function withTwoFactor(string $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'): static
    {
        return $this->afterCreating(function (Admin $admin) use ($secret) {
            $admin->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_recovery_codes' => [Hash::make('aaaaa-bbbbb')],
                'two_factor_confirmed_at' => now(),
            ])->save();
        });
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'role_id' => Role::firstWhere('name', config('rightally.super_admin_role'))?->id
                ?? Role::factory()->superAdmin(),
        ]);
    }

    public function invited(): static
    {
        return $this->state(['password' => null, 'invited_at' => now()]);
    }
}
