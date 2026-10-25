<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['attempt_id', 'question_id', 'option_id', 'text_answer', 'is_correct', 'points_awarded'])]
class QuizAnswer extends Model
{
    protected function casts(): array
    {
        return ['is_correct' => 'boolean', 'points_awarded' => 'float'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class);
    }
}
