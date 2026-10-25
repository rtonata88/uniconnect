<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/** SEC-2: chat access requires being a participant. */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->hasParticipant($user);
    }

    public function post(User $user, Conversation $conversation): bool
    {
        $p = $conversation->participant($user);

        return $p !== null && (! $conversation->announcements_only || $p->role === 'admin');
    }

    /** Rename, add/remove members (groups) and announcements-only (course chats). */
    public function manage(User $user, Conversation $conversation): bool
    {
        return $conversation->type !== 'direct' && $conversation->isAdmin($user);
    }
}
