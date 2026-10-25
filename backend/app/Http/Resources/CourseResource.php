<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Course */
class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'semester' => $this->semester,
            'my_role' => $this->pivot?->role ?? ($request->user() ? $this->roleOf($request->user()) : null),
            'lecturers' => $this->whenLoaded('lecturers', fn () => $this->lecturers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])),
            'students_count' => $this->whenCounted('students'),
            'conversation_id' => $this->conversation?->id,
            'updated_at' => $this->updated_at?->toIso8601ZuluString('microsecond'),
            'deleted_at' => $this->deleted_at?->toIso8601ZuluString(),
        ];
    }
}
