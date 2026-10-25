<?php

namespace App\Events;

use App\Models\Quiz;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when the quiz becomes visible to students. */
class QuizPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(public Quiz $quiz) {}
}
