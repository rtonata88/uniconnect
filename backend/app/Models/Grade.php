<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['grade_item_id', 'student_id', 'score', 'max_score', 'comment', 'graded_by'])]
class Grade extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['score' => 'float', 'max_score' => 'float'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(GradeItem::class, 'grade_item_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
