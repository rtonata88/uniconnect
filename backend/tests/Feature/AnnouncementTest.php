<?php

use App\Events\AnnouncementPosted;
use App\Models\Announcement;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

it('LMS-4 lecturer posts an announcement and it is dispatched', function () {
    Event::fake([AnnouncementPosted::class]);
    [$course, $lecturer, $student] = courseWithMembers();
    $this->actingAs($lecturer)->postJson("/api/courses/{$course->id}/announcements", ['title' => 'Exam', 'body' => 'Friday 9:00'])
        ->assertCreated()->assertJsonPath('data.author.id', $lecturer->id);
    Event::assertDispatched(AnnouncementPosted::class);
    $this->actingAs($student)->getJson("/api/courses/{$course->id}/announcements")->assertJsonCount(1, 'data');
});

it('MOB-5 announcement drafts from the outbox are idempotent on client_uuid', function () {
    [$course, $lecturer] = courseWithMembers();
    $payload = ['title' => 'Exam', 'body' => 'Friday', 'client_uuid' => (string) Str::uuid()];
    $this->actingAs($lecturer)->postJson("/api/courses/{$course->id}/announcements", $payload)->assertCreated();
    $this->actingAs($lecturer)->postJson("/api/courses/{$course->id}/announcements", $payload)->assertOk();
    expect(Announcement::count())->toBe(1);
});

it('SEC-2 students cannot post announcements', function () {
    [$course, , $student] = courseWithMembers();
    $this->actingAs($student)->postJson("/api/courses/{$course->id}/announcements", ['title' => 'x', 'body' => 'y'])->assertForbidden();
});

it('SEC-8 stores user text as given (rendered as plain text by the client)', function () {
    [$course, $lecturer] = courseWithMembers();
    $this->actingAs($lecturer)->postJson("/api/courses/{$course->id}/announcements", ['title' => '<b>x</b>', 'body' => '<script>alert(1)</script>'])
        ->assertCreated()->assertJsonPath('data.body', '<script>alert(1)</script>');
});
