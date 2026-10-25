<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\User;

class AddedToGroupNotification extends AppNotification
{
    public function __construct(public Conversation $conversation, public User $by)
    {
        parent::__construct();
    }

    public function category(): string
    {
        return 'groups';
    }

    public function title(): string
    {
        return "Added to \"{$this->conversation->title}\"";
    }

    public function body(): string
    {
        return "{$this->by->name} added you to the group.";
    }

    public function url(): string
    {
        return "/chats/{$this->conversation->id}";
    }
}
