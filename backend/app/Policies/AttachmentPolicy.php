<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

/**
 * SEC-4: a file is visible to its uploader and to anyone who can see an item
 * that references it (material, assignment, submission, chat message).
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        if ($attachment->owner_id === $user->id) {
            return true;
        }

        foreach ($attachment->materials()->with('course')->get() as $material) {
            if ($user->can('view', $material)) {
                return true;
            }
        }
        foreach ($attachment->assignments()->with('course')->get() as $assignment) {
            if ($user->can('view', $assignment)) {
                return true;
            }
        }
        foreach ($attachment->submissions()->with('assignment.course')->get() as $submission) {
            if ($user->can('view', $submission)) {
                return true;
            }
        }

        return $this->visibleThroughMessages($user, $attachment);
    }

    protected function visibleThroughMessages(User $user, Attachment $attachment): bool
    {
        return \App\Models\Message::where('attachment_id', $attachment->id)
            ->whereIn('conversation_id', \App\Models\ConversationParticipant::where('user_id', $user->id)->select('conversation_id'))
            ->exists();
    }
}
