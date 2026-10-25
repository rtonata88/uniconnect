<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assignment> */
class AssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => 'Assignment: '.fake()->words(3, true),
            'instructions' => fake()->paragraph(),
            'due_at' => now()->addDays(2),
            'max_score' => 100,
            'allow_late' => false,
            'late_penalty_percent' => 0,
            'published_at' => now()->subDay(),
        ];
    }

    public function pastDue(bool $allowLate = false, int $penalty = 0): static
    {
        return $this->state(['due_at' => now()->subDay(), 'allow_late' => $allowLate, 'late_penalty_percent' => $penalty]);
    }

    public function draft(): static
    {
        return $this->state(['published_at' => null]);
    }
}
