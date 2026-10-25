<?php

namespace App\Http\Resources;

use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quiz */
class QuizResource extends JsonResource
{
    /** Include questions with correct answers (lecturers only). */
    public bool $withAnswers = false;

    public function withAnswers(bool $value = true): static
    {
        $this->withAnswers = $value;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $myAttempts = $this->relationLoaded('myAttempts') ? $this->myAttempts : null;

        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'instructions' => $this->instructions,
            'opens_at' => $this->opens_at?->toIso8601ZuluString(),
            'closes_at' => $this->closes_at?->toIso8601ZuluString(),
            'time_limit_minutes' => $this->time_limit_minutes,
            'max_attempts' => $this->max_attempts,
            'shuffle_questions' => $this->shuffle_questions,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'is_open' => $this->isOpen(),
            'question_count' => $this->relationLoaded('questions') ? $this->questions->count() : $this->whenCounted('questions'),
            'max_score' => $this->relationLoaded('questions') ? $this->maxScore() : null,
            'questions' => $this->when($this->withAnswers && $this->relationLoaded('questions'), fn () => QuestionResource::collection($this->questions)->map(fn ($q) => $q->withAnswers())),
            'attempts_count' => $this->whenCounted('attempts'),
            'my_attempts' => $myAttempts ? AttemptResource::collection($myAttempts) : null,
            'grade_released' => (bool) $this->gradeItem?->isReleased(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString('microsecond'),
        ];
    }
}
