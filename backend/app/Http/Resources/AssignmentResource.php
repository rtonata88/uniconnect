<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Assignment */
class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'instructions' => $this->instructions,
            'due_at' => $this->due_at->toIso8601ZuluString(),
            'max_score' => $this->max_score,
            'allow_late' => $this->allow_late,
            'late_penalty_percent' => $this->late_penalty_percent,
            'attachment' => $this->attachment ? new AttachmentResource($this->attachment) : null,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'submissions_count' => $this->whenCounted('submissions'),
            'my_submission' => $this->whenLoaded('mySubmission', fn () => $this->mySubmission ? new SubmissionResource($this->mySubmission) : null),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString('microsecond'),
        ];
    }
}
