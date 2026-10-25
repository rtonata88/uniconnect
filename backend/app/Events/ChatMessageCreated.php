<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

/** Domain event for side effects of a new chat message (push to offline recipients). */
class ChatMessageCreated
{
    use Dispatchable;

    public function __construct(public Message $message) {}
}
