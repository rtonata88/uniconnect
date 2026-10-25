<?php

namespace App\Http\Resources;

use App\Models\QuizQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QuizQuestion */
class QuestionResource extends JsonResource
{
    public bool $answers = false;

    public function withAnswers(bool $value = true): static
    {
        $this->answers = $value;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'prompt' => $this->prompt,
            'points' => $this->points,
            'position' => $this->position,
            'correct_text' => $this->when($this->answers, $this->correct_text),
            'options' => $this->options->map(fn ($o) => array_filter([
                'id' => $o->id,
                'text' => $o->text,
                'position' => $o->position,
                'is_correct' => $this->answers ? $o->is_correct : null,
            ], fn ($v) => $v !== null))->values(),
        ];
    }
}
