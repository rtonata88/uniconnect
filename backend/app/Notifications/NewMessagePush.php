<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * NOT-3: push for a new chat message (sender and short preview). Push only — chat
 * messages have their own unread counts, so they are not added to the bell.
 */
class NewMessagePush extends AppNotification
{
    public function __construct(public Message $message)
    {
        parent::__construct();
    }

    public function via(User $notifiable): array
    {
        return $this->shouldPush($notifiable) ? [WebPushChannel::class] : [];
    }

    public function category(): string
    {
        return 'messages';
    }

    public function title(): string
    {
        $c = $this->message->conversation;
        $sender = $this->message->sender?->name ?? 'UniConnect';

        return $c->type === 'direct' ? $sender : "{$sender} @ {$c->title}";
    }

    public function body(): string
    {
        return match ($this->message->type) {
            'image' => '📷 Photo',
            'file' => '📎 '.($this->message->attachment?->original_name ?? 'File'),
            default => Str::limit((string) $this->message->body, 120),
        };
    }

    public function url(): string
    {
        return "/chats/{$this->message->conversation_id}";
    }
}
