<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * MSG-5: delivered to every participant connected to any Reverb instance (Redis
 * pub/sub scaling). Queued through Horizon; the sender's socket is excluded (toOthers).
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** Serialised now so the queued job never needs to decrypt or reload the message. */
    public array $payload;

    public int $conversationId;

    public function __construct(Message $message)
    {
        $this->conversationId = $message->conversation_id;
        $this->payload = (new MessageResource($message->loadMissing('sender', 'attachment', 'replyTo.sender')))->resolve(request());
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
        return 'MessageSent';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->payload];
    }
}
