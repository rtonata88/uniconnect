<?php

namespace App\Notifications\Channels;

use App\Events\NotificationCreated;
use App\Models\User;
use App\Notifications\AppNotification;

/**
 * Sends a notification over the socket as `NotificationCreated` (PRD 8.1 event name;
 * Laravel's built-in broadcast channel would name it BroadcastNotificationCreated).
 */
class RealtimeChannel
{
    public function send(User $notifiable, AppNotification $notification): void
    {
        NotificationCreated::dispatch($notifiable->id, [
            'id' => $notification->id,
            ...$notification->toArray($notifiable),
            'read_at' => null,
            'created_at' => now()->toIso8601ZuluString(),
        ]);
    }
}
