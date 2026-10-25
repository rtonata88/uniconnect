<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Domain event for side effects of a new chat message (push to offline recipients). */
class ChatMessageCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Message $message) {}
}
