<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** NOT-1: "added to group" notification. */
class AddedToGroup
{
    use Dispatchable;

    public function __construct(public Conversation $conversation, public User $user, public User $by) {}
}
