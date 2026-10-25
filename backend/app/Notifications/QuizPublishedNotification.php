<?php

namespace App\Notifications;

use App\Models\Quiz;

class QuizPublishedNotification extends AppNotification
{
    public function __construct(public Quiz $quiz)
    {
        parent::__construct();
    }

    public function category(): string
    {
        return 'quizzes';
    }

    public function title(): string
    {
        return "New quiz in {$this->quiz->course->code}";
    }

    public function body(): string
    {
        return $this->quiz->title.($this->quiz->closes_at ? ' — closes '.$this->quiz->closes_at->timezone(config('app.display_timezone', 'Africa/Windhoek'))->format('D j M, H:i') : '');
    }

    public function url(): string
    {
        return "/quizzes/{$this->quiz->id}";
    }

    public function extra(): array
    {
        return ['course_id' => $this->quiz->course_id];
    }
}
