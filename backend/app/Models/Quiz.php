<?php

namespace App\Models;

use App\Models\Concerns\HasGradeItem;
use App\Models\Concerns\Publishable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['course_id', 'created_by', 'title', 'instructions', 'opens_at', 'closes_at', 'time_limit_minutes', 'max_attempts', 'shuffle_questions', 'published_at'])]
class Quiz extends Model
{
    use HasFactory, HasGradeItem, Publishable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'published_at' => 'datetime',
            'time_limit_minutes' => 'integer',
            'max_attempts' => 'integer',
            'shuffle_questions' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /** Attempts of the signed-in student. */
    public function myAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class)->where('student_id', auth()->id())->latest('id');
    }

    public function maxScore(): float
    {
        return (float) $this->questions->sum('points');
    }

    public function isOpen(): bool
    {
        return $this->isPublished()
            && (! $this->opens_at || $this->opens_at->lessThanOrEqualTo(now()))
            && (! $this->closes_at || $this->closes_at->greaterThan(now()));
    }

    public function gradeItemMaxScore(): float
    {
        // query, not the (possibly stale) loaded relation: questions are saved after the quiz
        return (float) $this->questions()->sum('points') ?: 1;
    }
}
