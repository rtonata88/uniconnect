<?php

use App\Models\User;

function authChannel($test, User $user, string $channel)
{
    // switch to a signing broadcaster only for the auth call (test data is created with the null one)
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test',
    ]);
    app('Illuminate\Broadcasting\BroadcastManager')->purge('reverb');
    // channel callbacks were registered against the null broadcaster at boot
    require base_path('routes/channels.php');

    return $test->actingAs($user)->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
}

it('SEC-3 private-user.{id} is only for that user', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    authChannel($this, $a, "private-user.{$a->id}")->assertOk()->assertJsonStructure(['auth']);
    authChannel($this, $a, "private-user.{$b->id}")->assertForbidden();
});

it('SEC-3 presence-conversation.{id} is only for participants', function () {
    [$course, , $student] = courseWithMembers();
    $chat = $course->conversation;
    authChannel($this, $student, "presence-conversation.{$chat->id}")->assertOk()->assertJsonStructure(['auth', 'channel_data']);
    authChannel($this, User::factory()->create(), "presence-conversation.{$chat->id}")->assertForbidden();
});

it('SEC-3 private-course.{id} is only for enrolled users', function () {
    [$course, , $student] = courseWithMembers();
    authChannel($this, $student, "private-course.{$course->id}")->assertOk();
    authChannel($this, User::factory()->create(), "private-course.{$course->id}")->assertForbidden();
});

it('SEC-3 channel auth requires a token', function () {
    config(['broadcasting.default' => 'reverb']);
    $this->postJson('/api/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'presence-online'])->assertUnauthorized();
});
