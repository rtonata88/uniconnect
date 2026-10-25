<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['course_id', 'title', 'source_type', 'source_id', 'max_score', 'weight_percent', 'released_at'])]
class GradeItem extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['max_score' => 'float', 'weight_percent' => 'float', 'released_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }
}
