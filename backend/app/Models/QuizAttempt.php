<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['quiz_id', 'student_id', 'started_at', 'deadline_at', 'submitted_at', 'auto_submitted', 'question_order', 'score', 'max_score', 'needs_manual_grading'])]
class QuizAttempt extends Model
{
    /** Network latency allowance after the deadline for the final autosave/submit. */
    public const GRACE_SECONDS = 15;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'submitted_at' => 'datetime',
            'auto_submitted' => 'boolean',
            'question_order' => 'array',
            'score' => 'float',
            'max_score' => 'float',
            'needs_manual_grading' => 'boolean',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class, 'attempt_id');
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /** Answers are accepted until the deadline plus a short grace period. */
    public function acceptsAnswers(): bool
    {
        return ! $this->isSubmitted()
            && (! $this->deadline_at || now()->lessThanOrEqualTo($this->deadline_at->copy()->addSeconds(self::GRACE_SECONDS)));
    }

    public function isExpired(): bool
    {
        return $this->deadline_at !== null && now()->greaterThan($this->deadline_at->copy()->addSeconds(self::GRACE_SECONDS));
    }
}
