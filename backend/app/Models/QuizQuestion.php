<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['quiz_id', 'type', 'prompt', 'points', 'position', 'correct_text'])]
class QuizQuestion extends Model
{
    public const TYPES = ['mcq', 'true_false', 'short_answer'];

    protected function casts(): array
    {
        return ['points' => 'float', 'position' => 'integer'];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuizOption::class, 'question_id')->orderBy('position');
    }
}
