<?php

namespace App\Events;

use App\Models\Announcement;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when the item becomes visible to students; broadcast on private-course.{id} (PRD 8.1). */
class AnnouncementPosted implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("course.{$this->announcement->course_id}")];
    }

    public function broadcastAs(): string
    {
        return 'AnnouncementPosted';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->announcement->id, 'course_id' => $this->announcement->course_id, 'title' => $this->announcement->title];
    }

    public function __construct(public Announcement $announcement) {}
}
