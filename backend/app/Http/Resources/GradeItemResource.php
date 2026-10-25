<?php

namespace App\Http\Resources;

use App\Support\Lms\Gradebook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\GradeItem */
class GradeItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_url' => Gradebook::sourceUrl($this->resource),
            'max_score' => $this->max_score,
            'weight_percent' => $this->weight_percent,
            'released_at' => $this->released_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString('microsecond'),
        ];
    }
}
