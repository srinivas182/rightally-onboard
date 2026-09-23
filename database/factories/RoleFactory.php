<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Role> */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->jobTitle(), 'is_system' => false];
    }

    public function superAdmin(): static
    {
        return $this->state(['name' => config('rightally.super_admin_role'), 'is_system' => true]);
    }
}
