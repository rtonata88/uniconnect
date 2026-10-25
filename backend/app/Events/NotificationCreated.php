<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** NOT-2: a new notification, live on private-user.{id} (the bell updates without refresh). */
class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public int $userId, public array $notification) {}

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'NotificationCreated';
    }

    public function broadcastWith(): array
    {
        return $this->notification;
    }
}
