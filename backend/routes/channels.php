<?php

use App\Models\Conversation;
use App\Models\Course;
use Illuminate\Support\Facades\Broadcast;

/*
| SEC-3 channel authorisation (POST /api/broadcasting/auth with the Sanctum token)
*/

// private-user.{id}: only that user
Broadcast::channel('user.{id}', fn ($user, $id) => (int) $user->id === (int) $id);

// presence-conversation.{id}.{version}: current participants of the current membership version only
Broadcast::channel('conversation.{conversation}.{version}', function ($user, Conversation $conversation, $version) {
    return (int) $version === $conversation->channel_version && $conversation->hasParticipant($user)
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});

// private-course.{id}: enrolled users
Broadcast::channel('course.{course}', fn ($user, Course $course) => $course->isMember($user));

// presence-online: everyone signed in (online dots, push suppression). Only ids are shared.
Broadcast::channel('online', fn ($user) => ['id' => $user->id]);
