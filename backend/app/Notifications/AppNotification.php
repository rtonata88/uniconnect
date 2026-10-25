<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\RealtimeChannel;
use App\Support\Notify\Preferences;
use App\Support\Notify\Presence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * NOT-1..3: every notification is stored (bell), sent on private-user.{id} as
 * NotificationCreated (live bell + toast, RealtimeChannel) and, if the user is not in the app and has
 * push enabled for the category, sent as Web Push. Fan-out runs on the `push` queue.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('push');
    }

    /** announcements | assignments | quizzes | grades | reminders | groups | messages */
    abstract public function category(): string;

    abstract public function title(): string;

    abstract public function body(): string;

    /** Path in the web app to open (MOB-7). */
    abstract public function url(): string;

    public function extra(): array
    {
        return [];
    }

    public function via(User $notifiable): array
    {
        $channels = ['database', RealtimeChannel::class];
        if ($this->shouldPush($notifiable)) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    protected function shouldPush(User $user): bool
    {
        return config('webpush.vapid.public_key')
            && Preferences::pushEnabled($user, $this->category())
            && ! app(Presence::class)->isOnline($user->id);
    }

    public function toArray(User $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            ...$this->extra(),
        ];
    }

    public function databaseType(User $notifiable): string
    {
        return $this->category();
    }

    public function toWebPush(User $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title())
            ->body($this->body())
            ->icon('/pwa-192x192.png')
            ->badge('/pwa-64x64.png')
            ->tag($this->category().':'.$this->url())
            ->data(['url' => $this->url(), 'category' => $this->category(), 'notification_id' => $this->id])
            ->options(['TTL' => 86400, 'urgency' => 'normal']);
    }
}
