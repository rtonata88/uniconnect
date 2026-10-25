<?php

namespace App\Events;

use App\Models\CourseMaterial;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when the item becomes visible to students; broadcast on private-course.{id} (PRD 8.1). */
class MaterialPublished implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("course.{$this->material->course_id}")];
    }

    public function broadcastAs(): string
    {
        return 'MaterialPublished';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->material->id, 'course_id' => $this->material->course_id, 'title' => $this->material->title];
    }

    public function __construct(public CourseMaterial $material) {}
}
