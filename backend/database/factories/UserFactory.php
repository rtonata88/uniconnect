<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Password123!'),
            'role' => Role::Student,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => Role::Admin]);
    }

    public function lecturer(): static
    {
        return $this->state(['role' => Role::Lecturer]);
    }

    public function student(): static
    {
        return $this->state(fn () => ['role' => Role::Student, 'student_number' => 'S'.fake()->unique()->numerify('######')]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
