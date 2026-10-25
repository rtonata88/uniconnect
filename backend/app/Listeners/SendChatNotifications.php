<?php

namespace App\Listeners;

use App\Events\AddedToGroup;
use App\Events\ChatMessageCreated;
use App\Notifications\AddedToGroupNotification;
use App\Notifications\NewMessagePush;
use App\Support\Notify\Presence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * NOT-3: offline recipients of a chat message get a push (sender + preview);
 * muted conversations are skipped (MSG-12). NOT-1: "added to group" notification.
 */
class SendChatNotifications implements ShouldQueue
{
    /** fan-out runs on the worker, never inside the request */
    public string $queue = 'push';

    public function handleMessage(ChatMessageCreated $event): void
    {
        $message = $event->message;
        if ($message->type === 'system') {
            return;
        }
        $online = app(Presence::class)->onlineUserIds();
        $recipients = $message->conversation->participants()
            ->with('user')
            ->where('user_id', '!=', $message->sender_id)
            ->whereNotIn('user_id', $online)
            ->where(fn ($q) => $q->whereNull('muted_until')->orWhere('muted_until', '<', now()))
            ->get()->pluck('user')->filter();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new NewMessagePush($message));
        }
    }

    public function handleAddedToGroup(AddedToGroup $event): void
    {
        $event->user->notify(new AddedToGroupNotification($event->conversation, $event->by));
    }
}
