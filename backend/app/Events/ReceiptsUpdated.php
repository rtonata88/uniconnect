<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** MSG-7: tick changes for the sender's messages, on private-user.{senderId}. */
class ReceiptsUpdated implements ShouldBroadcast
{
    use Dispatchable;

    /** @param  list<array{message_id:int, conversation_id:int, status:string, delivered:int, read:int, recipients:int}>  $receipts */
    public function __construct(public int $senderId, public array $receipts) {}

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->senderId}")];
    }

    public function broadcastAs(): string
    {
        return 'ReceiptsUpdated';
    }

    public function broadcastWith(): array
    {
        return ['receipts' => $this->receipts];
    }
}
