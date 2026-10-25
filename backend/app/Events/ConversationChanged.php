<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `ConversationCreated` / `ConversationUpdated` on private-user.{id} (PRD 8.1).
 * The payload is minimal; the client re-fetches the conversation it is allowed to see.
 * reason: created | updated | removed
 */
class ConversationChanged implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public int $userId, public int $conversationId, public string $reason) {}

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
        return $this->reason === 'created' ? 'ConversationCreated' : 'ConversationUpdated';
    }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'reason' => $this->reason];
    }
}
