<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class MessageDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $conversationId, public int $messageId)
    {
        $this->dontBroadcastToCurrentUser();
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastOn(): array
    {
        return [new PresenceChannel("conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'MessageDeleted';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->messageId, 'conversation_id' => $this->conversationId];
    }
}
