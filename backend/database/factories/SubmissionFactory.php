<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Submission> */
class SubmissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'assignment_id' => Assignment::factory(),
            'student_id' => User::factory()->student(),
            'client_uuid' => (string) Str::uuid(),
            'text' => fake()->paragraph(),
            'submitted_at' => now(),
            'is_late' => false,
        ];
    }
}
