<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Quiz> */
class QuizFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => 'Quiz: '.fake()->words(2, true),
            'instructions' => 'Answer all questions.',
            'opens_at' => now()->subHour(),
            'closes_at' => now()->addWeek(),
            'time_limit_minutes' => 20,
            'max_attempts' => 1,
            'shuffle_questions' => false,
            'published_at' => now()->subHour(),
        ];
    }

    /** One MCQ (2 pts), one true/false (1 pt), one short answer with key "TCP" (2 pts), one free short answer (3 pts). */
    public function withQuestions(): static
    {
        return $this->afterCreating(function (Quiz $quiz) {
            $mcq = $quiz->questions()->create(['type' => 'mcq', 'prompt' => 'Which store does UniConnect use for queues?', 'points' => 2, 'position' => 0]);
            $mcq->options()->createMany([
                ['text' => 'MySQL', 'is_correct' => false, 'position' => 0],
                ['text' => 'Redis', 'is_correct' => true, 'position' => 1],
                ['text' => 'MinIO', 'is_correct' => false, 'position' => 2],
            ]);
            $tf = $quiz->questions()->create(['type' => 'true_false', 'prompt' => 'WebSockets are bidirectional.', 'points' => 1, 'position' => 1]);
            $tf->options()->createMany([
                ['text' => 'True', 'is_correct' => true, 'position' => 0],
                ['text' => 'False', 'is_correct' => false, 'position' => 1],
            ]);
            $quiz->questions()->create(['type' => 'short_answer', 'prompt' => 'Reliable transport protocol?', 'points' => 2, 'position' => 2, 'correct_text' => 'TCP']);
            $quiz->questions()->create(['type' => 'short_answer', 'prompt' => 'Explain eventual consistency.', 'points' => 3, 'position' => 3]);
            $quiz->syncGradeItem();
        });
    }
}
