<?php

namespace App\Models\Concerns;

use App\Models\GradeItem;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Assignments and quizzes own a gradebook column (grade item) that follows their
 * title / max score and disappears when they are deleted (LMS-10).
 */
trait HasGradeItem
{
    public static function bootHasGradeItem(): void
    {
        static::created(fn ($model) => $model->syncGradeItem());
        static::updated(function ($model) {
            if ($model->wasChanged(['title', 'max_score'])) {
                $model->syncGradeItem();
            }
        });
        static::deleted(fn ($model) => $model->gradeItem()->first()?->delete());
    }

    public function gradeItem(): HasOne
    {
        return $this->hasOne(GradeItem::class, 'source_id')->where('source_type', $this->gradeSourceType());
    }

    public function gradeSourceType(): string
    {
        return strtolower(class_basename($this));
    }

    public function gradeItemMaxScore(): float
    {
        return (float) $this->max_score;
    }

    public function syncGradeItem(): GradeItem
    {
        return GradeItem::updateOrCreate(
            ['source_type' => $this->gradeSourceType(), 'source_id' => $this->id],
            ['course_id' => $this->course_id, 'title' => $this->title, 'max_score' => $this->gradeItemMaxScore()],
        );
    }
}
