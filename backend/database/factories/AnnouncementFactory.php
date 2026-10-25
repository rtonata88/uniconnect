<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Announcement> */
class AnnouncementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
            'published_at' => now()->subHour(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['published_at' => null]);
    }
}
