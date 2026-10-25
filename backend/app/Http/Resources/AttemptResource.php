<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\QuizAttempt */
class AttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isLecturer = $viewer && $viewer->id !== $this->student_id;
        $released = (bool) $this->quiz?->gradeItem?->isReleased();
        // students see scores and correctness only after release (LMS-10)
        $showResult = $this->isSubmitted() && ($isLecturer || $released);

        return [
            'id' => $this->id,
            'quiz_id' => $this->quiz_id,
            'student' => $this->whenLoaded('student', fn () => ['id' => $this->student->id, 'name' => $this->student->name, 'student_number' => $this->student->student_number]),
            'started_at' => $this->started_at->toIso8601ZuluString(),
            'deadline_at' => $this->deadline_at?->toIso8601ZuluString(),
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'auto_submitted' => $this->auto_submitted,
            'needs_manual_grading' => $this->needs_manual_grading,
            'score' => $showResult ? $this->score : null,
            'max_score' => $this->max_score ?: null,
            'grade_released' => $released,
            'server_now' => now()->toIso8601ZuluString('millisecond'),
            'questions' => $this->when($this->relationLoaded('quiz') && $this->quiz->relationLoaded('questions'), function () use ($isLecturer) {
                $byId = $this->quiz->questions->keyBy('id');
                $order = $this->question_order ?: $byId->keys()->all();

                return collect($order)->map(fn ($id) => $byId->get($id))->filter()
                    ->map(fn ($q) => (new QuestionResource($q))->withAnswers($isLecturer))->values();
            }),
            'answers' => $this->whenLoaded('answers', fn () => $this->answers->map(fn ($a) => [
                'question_id' => $a->question_id,
                'option_id' => $a->option_id,
                'text_answer' => $a->text_answer,
                'is_correct' => $this->isSubmitted() && ($isLecturer || $released) ? $a->is_correct : null,
                'points_awarded' => $this->isSubmitted() && ($isLecturer || $released) ? $a->points_awarded : null,
            ])->values()),
        ];
    }
}
