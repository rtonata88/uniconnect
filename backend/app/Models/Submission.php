<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['assignment_id', 'student_id', 'client_uuid', 'text', 'attachment_id', 'submitted_at', 'is_late', 'attempt', 'score', 'feedback', 'graded_by', 'graded_at'])]
class Submission extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
            'is_late' => 'boolean',
            'score' => 'float',
            'attempt' => 'integer',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    /** Grades become visible to the student when the assignment's grade item is released (LMS-10). */
    public function gradeReleased(): bool
    {
        return (bool) $this->assignment?->gradeItem?->isReleased();
    }

    /** Score after the late penalty (LMS-5 late policy). */
    public function finalScore(): ?float
    {
        if ($this->score === null) {
            return null;
        }
        $penalty = $this->is_late ? ($this->assignment->late_penalty_percent ?? 0) : 0;

        return round($this->score * (100 - $penalty) / 100, 2);
    }
}
