<?php

namespace App\Notifications;

use App\Models\Assignment;

class AssignmentPublishedNotification extends AppNotification
{
    public function __construct(public Assignment $assignment)
    {
        parent::__construct();
    }

    public function category(): string
    {
        return 'assignments';
    }

    public function title(): string
    {
        return "New assignment in {$this->assignment->course->code}";
    }

    public function body(): string
    {
        return "{$this->assignment->title} — due ".$this->assignment->due_at->timezone(config('app.display_timezone', 'Africa/Windhoek'))->format('D j M, H:i');
    }

    public function url(): string
    {
        return "/assignments/{$this->assignment->id}";
    }

    public function extra(): array
    {
        return ['course_id' => $this->assignment->course_id];
    }
}
