<?php

namespace App\Http\Resources;

use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Submission */
class SubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        // students only see their score once the grade is released (LMS-10)
        $showGrade = $viewer && ($viewer->id !== $this->student_id || $this->gradeReleased());

        return [
            'id' => $this->id,
            'assignment_id' => $this->assignment_id,
            'client_uuid' => $this->client_uuid,
            'student' => $this->whenLoaded('student', fn () => ['id' => $this->student->id, 'name' => $this->student->name, 'student_number' => $this->student->student_number]),
            'text' => $this->text,
            'attachment' => $this->attachment ? new AttachmentResource($this->attachment) : null,
            'submitted_at' => $this->submitted_at->toIso8601ZuluString(),
            'is_late' => $this->is_late,
            'attempt' => $this->attempt,
            'is_graded' => $this->graded_at !== null,
            'score' => $showGrade ? $this->score : null,
            'final_score' => $showGrade ? $this->finalScore() : null,
            'feedback' => $showGrade ? $this->feedback : null,
            'graded_at' => $showGrade ? $this->graded_at?->toIso8601ZuluString() : null,
            'grade_released' => $this->gradeReleased(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString('microsecond'),
        ];
    }
}
