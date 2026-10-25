<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Course> */
class CourseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('??###')),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'semester' => '2026 S2',
        ];
    }

    public function withLecturer(?User $lecturer = null): static
    {
        return $this->afterCreating(fn (Course $c) => $c->members()->attach(($lecturer ?? User::factory()->lecturer()->create())->id, ['role' => 'lecturer']));
    }

    public function withStudents(int $count = 2): static
    {
        return $this->afterCreating(fn (Course $c) => $c->members()->attach(
            User::factory()->student()->count($count)->create()->pluck('id')->mapWithKeys(fn ($id) => [$id => ['role' => 'student']])->all()
        ));
    }
}
