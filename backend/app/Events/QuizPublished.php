<?php

namespace App\Events;

use App\Models\Quiz;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when the quiz becomes visible to students. */
class QuizPublished implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("course.{$this->quiz->course_id}")];
    }

    public function broadcastAs(): string
    {
        return 'QuizPublished';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->quiz->id, 'course_id' => $this->quiz->course_id, 'title' => $this->quiz->title];
    }

    public function __construct(public Quiz $quiz) {}
}
