<?php

use App\Events\NotificationCreated;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\AddedToGroupNotification;
use App\Notifications\AnnouncementPostedNotification;
use App\Notifications\AssignmentPublishedNotification;
use App\Notifications\Channels\RealtimeChannel;
use App\Notifications\DeadlineReminderNotification;
use App\Notifications\GradesReleasedNotification;
use App\Notifications\NewMessagePush;
use App\Notifications\QuizPublishedNotification;
use App\Support\Enrolment;
use App\Support\Notify\Presence;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;

beforeEach(function () {
    // a throwaway VAPID key pair used only by the tests
    config(['webpush.vapid' => ['subject' => 'mailto:test@uniconnect.test', 'public_key' => 'BKnQdLqz-u4Sr_-1uJpGqhm_TMSqK2InQZcTmadvzNXAmPtC-qMrtJK6EoKtM-kYqQhobwHJpT-ThBGc56vpcwU', 'private_key' => 'gpTDqixgAnAqrCi1flmClDpPHoBy5PLGHc0bptqD-KI']]);
    // nobody is "in the app" unless a test says so
    $this->instance(Presence::class, new class extends Presence
    {
        public array $online = [];

        public function onlineUserIds(): array
        {
            return $this->online;
        }
    });
});

it('LMS-4 / NOT-1 an announcement notifies enrolled users except the author', function () {
    Notification::fake();
    [$course, $lecturer, $student] = courseWithMembers();
    $this->actingAs($lecturer)->postJson("/api/courses/{$course->id}/announcements", ['title' => 'Exam', 'body' => 'Friday'])->assertCreated();
    Notification::assertSentTo($student, AnnouncementPostedNotification::class, fn ($n, $channels) => in_array('database', $channels) && in_array(RealtimeChannel::class, $channels));
    Notification::assertNotSentTo($lecturer, AnnouncementPostedNotification::class);
});

it('NOT-1 new assignments and quizzes notify students', function () {
    Notification::fake();
    [$course, $lecturer, $student] = courseWithMembers();
    $this->actingAs($lecturer)->postJson("/api/courses/{$course->id}/assignments", ['title' => 'Lab', 'due_at' => now()->addWeek()->toIso8601String(), 'max_score' => 10]);
    $this->actingAs($lecturer)->postJson("/api/courses/{$course->id}/quizzes", ['title' => 'Q', 'questions' => [['type' => 'short_answer', 'prompt' => 'x', 'points' => 1]]]);
    Notification::assertSentTo($student, AssignmentPublishedNotification::class);
    Notification::assertSentTo($student, QuizPublishedNotification::class);
    Notification::assertNotSentTo($lecturer, AssignmentPublishedNotification::class);
});

it('NOT-1 released grades notify graded students', function () {
    Notification::fake();
    [$course, $lecturer, $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->create();
    $sub = Submission::factory()->for($a)->create(['student_id' => $student->id]);
    $this->actingAs($lecturer)->patchJson("/api/submissions/{$sub->id}/grade", ['score' => 50]);
    $this->actingAs($lecturer)->postJson("/api/grade-items/{$a->gradeItem->id}/release");
    Notification::assertSentTo($student, GradesReleasedNotification::class, fn ($n) => $n->url() === "/assignments/{$a->id}");
});

it('NOT-1 being added to a group notifies the member', function () {
    Notification::fake();
    [$course, , $student] = courseWithMembers();
    $other = User::factory()->student()->create();
    app(Enrolment::class)->enrol($course, [$other->id], 'student');
    $this->actingAs($student)->postJson('/api/conversations', ['type' => 'group', 'title' => 'G', 'user_ids' => [$other->id]]);
    Notification::assertSentTo($other, AddedToGroupNotification::class);
});

it('NOT-3 pushes new messages to offline recipients, not to online or muted ones', function () {
    Notification::fake();
    [$course, $lecturer, $student] = courseWithMembers();
    [$s2, $s3] = User::factory()->student()->count(2)->create()->all();
    app(Enrolment::class)->enrol($course, [$s2->id, $s3->id], 'student');
    $chat = $course->conversation;
    app(Presence::class)->online = [$s2->id];
    $this->actingAs($s3)->postJson("/api/conversations/{$chat->id}/mute", ['hours' => 8]);

    $this->actingAs($lecturer)->postJson("/api/conversations/{$chat->id}/messages", ['client_uuid' => (string) Str::uuid(), 'body' => 'Lab moved'])->assertCreated();

    Notification::assertSentTo($student, NewMessagePush::class, fn ($n, $channels) => $channels === [WebPushChannel::class]
        && $n->title() === "{$lecturer->name} @ {$chat->title}" && $n->body() === 'Lab moved' && $n->url() === "/chats/{$chat->id}");
    Notification::assertNotSentTo($s2, NewMessagePush::class);   // online in the app
    Notification::assertNotSentTo($s3, NewMessagePush::class);   // muted (MSG-12)
    Notification::assertNotSentTo($lecturer, NewMessagePush::class);
});

it('NOT-5 push respects per-category preferences; in-app is always on', function () {
    [, , $student] = courseWithMembers();
    $this->actingAs($student)->putJson('/api/notification-preferences', ['push' => ['announcements' => false]])
        ->assertOk()->assertJsonPath('push.announcements', false)->assertJsonPath('push.messages', true);

    $announcement = Announcement::factory()->create();
    $channels = (new AnnouncementPostedNotification($announcement))->via($student->fresh());
    expect($channels)->toContain('database')->toContain(RealtimeChannel::class)->not->toContain(WebPushChannel::class);

    $this->actingAs($student)->putJson('/api/notification-preferences', ['push' => ['announcements' => true]]);
    expect((new AnnouncementPostedNotification($announcement))->via($student->fresh()))->toContain(WebPushChannel::class);
});

it('NOT-3 no push while the user is active in the app', function () {
    [, , $student] = courseWithMembers();
    app(Presence::class)->online = [$student->id];
    $channels = (new AnnouncementPostedNotification(Announcement::factory()->create()))->via($student);
    expect($channels)->not->toContain(WebPushChannel::class);
});

it('NOT-4 reminds students who have not submitted, 24 h and 1 h before, once each', function () {
    Notification::fake();
    [$course, , $student] = courseWithMembers();
    $done = User::factory()->student()->create();
    app(Enrolment::class)->enrol($course, [$done->id], 'student');
    $a = Assignment::factory()->for($course)->create(['due_at' => now()->addHours(20)]);
    Submission::factory()->for($a)->create(['student_id' => $done->id]);

    $this->artisan('assignments:remind')->assertSuccessful();
    $this->artisan('assignments:remind')->assertSuccessful(); // idempotent
    Notification::assertSentToTimes($student, DeadlineReminderNotification::class, 1);
    Notification::assertNotSentTo($done, DeadlineReminderNotification::class);

    $this->travel(19 * 60 + 10)->minutes();
    $this->artisan('assignments:remind');
    Notification::assertSentTo($student, DeadlineReminderNotification::class, fn ($n) => $n->window === '1h');
    Notification::assertSentToTimes($student, DeadlineReminderNotification::class, 2);
});

it('NOT-1 lists notifications with an unread count and marks them read', function () {
    [, , $student] = courseWithMembers();
    $student->notify(new AnnouncementPostedNotification(Announcement::factory()->create(['title' => 'Hello'])));
    $student->notify(new AnnouncementPostedNotification(Announcement::factory()->create()));

    $res = $this->actingAs($student)->getJson('/api/notifications')->assertOk()->assertJsonPath('unread_count', 2);
    expect($res->json('data.0'))->toHaveKeys(['id', 'category', 'title', 'body', 'url', 'read_at', 'created_at']);

    $this->actingAs($student)->postJson('/api/notifications/read', ['ids' => [$res->json('data.0.id')]])->assertJsonPath('unread_count', 1);
    $this->actingAs($student)->postJson('/api/notifications/read', ['all' => true])->assertJsonPath('unread_count', 0);
});

it('NOT-2 notifications are broadcast on private-user.{id} as NotificationCreated', function () {
    Event::fake([NotificationCreated::class]);
    $user = User::factory()->create();
    $user->notify(new AnnouncementPostedNotification(Announcement::factory()->create()));
    Event::assertDispatched(NotificationCreated::class, fn ($e) => $e->broadcastOn()[0]->name === "private-user.{$user->id}"
        && $e->broadcastAs() === 'NotificationCreated'
        && $e->broadcastWith()['id'] === $user->notifications()->first()->id
        && isset($e->broadcastWith()['title'], $e->broadcastWith()['url']));
});

it('NOT-3 stores and removes Web Push subscriptions', function () {
    $user = User::factory()->create();
    $sub = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'key', 'auth' => 'auth'], 'contentEncoding' => 'aes128gcm'];
    $this->actingAs($user)->postJson('/api/push/webpush/subscribe', $sub)->assertCreated();
    expect($user->pushSubscriptions()->count())->toBe(1);
    $this->actingAs($user)->postJson('/api/push/webpush/subscribe', ['endpoint' => 'http://insecure/x', 'keys' => $sub['keys']])->assertStatus(422);
    $this->actingAs($user)->deleteJson('/api/push/webpush/subscribe', ['endpoint' => $sub['endpoint']])->assertOk();
    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('NOT-3 the Web Push payload carries the URL to open (MOB-7)', function () {
    $user = User::factory()->create();
    $a = Assignment::factory()->create();
    $msg = (new AssignmentPublishedNotification($a))->toWebPush($user, null)->toArray();
    expect($msg['title'])->toContain($a->course->code)->and($msg['data']['url'])->toBe("/assignments/{$a->id}");
});
