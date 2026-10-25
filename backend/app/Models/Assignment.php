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

#[Fillable(['course_id', 'created_by', 'title', 'instructions', 'due_at', 'max_score', 'allow_late', 'late_penalty_percent', 'attachment_id', 'published_at'])]
class Assignment extends Model
{
    use HasFactory, HasGradeItem, Publishable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'published_at' => 'datetime',
            'reminded_24h_at' => 'datetime',
            'reminded_1h_at' => 'datetime',
            'max_score' => 'float',
            'allow_late' => 'boolean',
            'late_penalty_percent' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /** Submission of the current user (eager-loaded for students). */
    public function mySubmission(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Submission::class)->where('student_id', auth()->id());
    }

    public function isPastDue(): bool
    {
        return now()->greaterThan($this->due_at);
    }
}
