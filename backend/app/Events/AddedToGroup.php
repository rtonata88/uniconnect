<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** NOT-1: "added to group" notification. */
class AddedToGroup
{
    use Dispatchable, SerializesModels;

    public function __construct(public Conversation $conversation, public User $user, public User $by) {}
}
