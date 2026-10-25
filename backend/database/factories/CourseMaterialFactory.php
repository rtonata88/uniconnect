<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Course;
use App\Models\CourseMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CourseMaterial> */
class CourseMaterialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => 'Week notes: '.fake()->words(3, true),
            'description' => fake()->sentence(),
            'week' => fake()->numberBetween(1, 12),
            'attachment_id' => Attachment::factory(),
            'published_at' => now()->subDay(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['published_at' => null]);
    }
}
