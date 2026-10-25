<?php

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Support\Str;

class AnnouncementPostedNotification extends AppNotification
{
    public function __construct(public Announcement $announcement)
    {
        parent::__construct();
    }

    public function category(): string
    {
        return 'announcements';
    }

    public function title(): string
    {
        return "{$this->announcement->course->code}: {$this->announcement->title}";
    }

    public function body(): string
    {
        return Str::limit($this->announcement->body, 140);
    }

    public function url(): string
    {
        return "/courses/{$this->announcement->course_id}/announcements";
    }

    public function extra(): array
    {
        return ['course_id' => $this->announcement->course_id];
    }
}
