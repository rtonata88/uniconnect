<?php

namespace App\Listeners;

use App\Events\EnrolmentChanged;
use App\Support\Chat\ChatService;

/** PRD §6: enrolling adds the user to the course chat, removing the enrolment removes them; lecturers are admins. */
class SyncCourseChatMembers
{
    public function __construct(private ChatService $chat) {}

    public function handle(EnrolmentChanged $event): void
    {
        $conversation = $event->course->conversation;
        if (! $conversation) {
            return;
        }
        if ($event->role === null) {
            $this->chat->removeParticipant($conversation, $event->user);
        } else {
            $this->chat->addParticipants($conversation, [$event->user], $event->role === 'lecturer' ? 'admin' : 'member', announce: false);
        }
    }
}
