<?php

namespace App\Notifications;

use App\Models\Assignment;

class DeadlineReminderNotification extends AppNotification
{
    public function __construct(public Assignment $assignment, public string $window)
    {
        parent::__construct();
    }

    public function category(): string
    {
        return 'reminders';
    }

    public function title(): string
    {
        return $this->window === '1h' ? 'Due in 1 hour' : 'Due tomorrow';
    }

    public function body(): string
    {
        return "{$this->assignment->course->code}: {$this->assignment->title} is due ".$this->assignment->due_at->timezone(config('app.display_timezone', 'Africa/Windhoek'))->format('D H:i').' and you have not submitted yet.';
    }

    public function url(): string
    {
        return "/assignments/{$this->assignment->id}";
    }

    public function extra(): array
    {
        return ['course_id' => $this->assignment->course_id, 'window' => $this->window];
    }
}
