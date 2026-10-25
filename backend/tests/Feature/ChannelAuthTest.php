<?php

use App\Models\User;
use App\Support\Enrolment;

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
    $chat = $course->conversation->fresh();
    authChannel($this, $student, "presence-{$chat->channelName()}")->assertOk()->assertJsonStructure(['auth', 'channel_data']);
    authChannel($this, User::factory()->create(), "presence-{$chat->channelName()}")->assertForbidden();
});

it('SEC-3 removing a member rotates the channel so the old subscription stops receiving', function () {
    [$course, , $student] = courseWithMembers();
    $old = $course->conversation->fresh()->channelName();
    app(Enrolment::class)->unenrol($course, [$student->id]);
    $new = $course->conversation->fresh()->channelName();

    expect($new)->not->toBe($old);
    authChannel($this, $student, "presence-{$new}")->assertForbidden();
    // a stale version cannot be authorised by anyone
    [, $lecturer] = [null, $course->lecturers()->first()];
    authChannel($this, $lecturer, "presence-{$old}")->assertForbidden();
    authChannel($this, $lecturer, "presence-{$new}")->assertOk();
});

it('presence-online shares only user ids', function () {
    $u = User::factory()->create();
    $data = json_decode(authChannel($this, $u, 'presence-online')->assertOk()->json('channel_data'), true);
    expect($data['user_info'])->toBe(['id' => $u->id]);
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
