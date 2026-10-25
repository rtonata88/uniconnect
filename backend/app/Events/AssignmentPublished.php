<?php

namespace App\Events;

use App\Models\Assignment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when the item becomes visible to students; broadcast on private-course.{id} (PRD 8.1). */
class AssignmentPublished implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("course.{$this->assignment->course_id}")];
    }

    public function broadcastAs(): string
    {
        return 'AssignmentPublished';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->assignment->id, 'course_id' => $this->assignment->course_id, 'title' => $this->assignment->title];
    }

    public function __construct(public Assignment $assignment) {}
}
