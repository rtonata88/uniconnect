<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['question_id', 'text', 'is_correct', 'position'])]
class QuizOption extends Model
{
    protected function casts(): array
    {
        return ['is_correct' => 'boolean', 'position' => 'integer'];
    }
}
