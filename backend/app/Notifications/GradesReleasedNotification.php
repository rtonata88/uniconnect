<?php

namespace App\Notifications;

use App\Models\GradeItem;

class GradesReleasedNotification extends AppNotification
{
    public function __construct(public GradeItem $item)
    {
        parent::__construct();
    }

    public function category(): string
    {
        return 'grades';
    }

    public function title(): string
    {
        return "Grade released in {$this->item->course->code}";
    }

    public function body(): string
    {
        return "Your result for \"{$this->item->title}\" is available.";
    }

    public function url(): string
    {
        return match ($this->item->source_type) {
            'assignment' => "/assignments/{$this->item->source_id}",
            'quiz' => "/quizzes/{$this->item->source_id}",
            default => "/courses/{$this->item->course_id}/grades",
        };
    }

    public function extra(): array
    {
        return ['course_id' => $this->item->course_id];
    }
}
