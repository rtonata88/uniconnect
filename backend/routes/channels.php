<?php

use App\Models\Conversation;
use App\Models\Course;
use Illuminate\Support\Facades\Broadcast;

/*
| SEC-3 channel authorisation (POST /api/broadcasting/auth with the Sanctum token)
*/

// private-user.{id}: only that user
Broadcast::channel('user.{id}', fn ($user, $id) => (int) $user->id === (int) $id);

// presence-conversation.{id}: participants only
Broadcast::channel('conversation.{conversation}', function ($user, Conversation $conversation) {
    return $conversation->hasParticipant($user) ? ['id' => $user->id, 'name' => $user->name] : false;
});

// private-course.{id}: enrolled users
Broadcast::channel('course.{course}', fn ($user, Course $course) => $course->isMember($user));

// presence-online: everyone signed in (online dots)
Broadcast::channel('online', fn ($user) => ['id' => $user->id, 'name' => $user->name]);
