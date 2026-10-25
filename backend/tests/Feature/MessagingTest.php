<?php

use App\Events\ConversationChanged;
use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Events\ReceiptsUpdated;
use App\Models\Conversation;
use App\Models\Course;
use App\Models\Message;
use App\Models\User;
use App\Support\Enrolment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

function send($test, User $user, Conversation $c, array $extra = [])
{
    return $test->actingAs($user)->postJson("/api/conversations/{$c->id}/messages", array_merge(['client_uuid' => (string) Str::uuid(), 'body' => 'Hello'], $extra));
}

function direct($test, User $a, User $b): Conversation
{
    return Conversation::find($test->actingAs($a)->postJson('/api/conversations', ['type' => 'direct', 'user_id' => $b->id])->json('data.id'));
}

it('MSG-4 creates a course chat with the course and keeps members in sync with enrolment', function () {
    $course = Course::factory()->create();
    $lecturer = User::factory()->lecturer()->create();
    $student = User::factory()->student()->create();
    app(Enrolment::class)->enrol($course, [$lecturer->id], 'lecturer');
    app(Enrolment::class)->enrol($course, [$student->id], 'student');

    $chat = $course->conversation;
    expect($chat->type)->toBe('course')
        ->and($chat->participant($lecturer)->role)->toBe('admin')
        ->and($chat->participant($student)->role)->toBe('member');

    app(Enrolment::class)->unenrol($course, [$student->id]);
    expect($chat->fresh()->hasParticipant($student))->toBeFalse();
});

it('MSG-2 a direct chat between two users is unique', function () {
    [, $lecturer, $student] = courseWithMembers();
    $first = $this->actingAs($student)->postJson('/api/conversations', ['type' => 'direct', 'user_id' => $lecturer->id])->assertCreated();
    $this->actingAs($lecturer)->postJson('/api/conversations', ['type' => 'direct', 'user_id' => $student->id])
        ->assertOk()->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.title', $student->name);
});

it('MSG-2 direct chats need a shared course, except for admins', function () {
    $a = User::factory()->student()->create();
    $b = User::factory()->student()->create();
    $this->actingAs($a)->postJson('/api/conversations', ['type' => 'direct', 'user_id' => $b->id])->assertStatus(422);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->postJson('/api/conversations', ['type' => 'direct', 'user_id' => $b->id])->assertCreated();
});

it('MSG-5 / PRD 9.2 sends in real time and is idempotent on client_uuid', function () {
    Event::fake([MessageSent::class]);
    [, $lecturer, $student] = courseWithMembers();
    $c = direct($this, $student, $lecturer);
    $uuid = (string) Str::uuid();

    send($this, $student, $c, ['client_uuid' => $uuid])->assertCreated()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.body', 'Hello');
    send($this, $student, $c, ['client_uuid' => $uuid])->assertOk();

    expect(Message::where('client_uuid', $uuid)->count())->toBe(1);
    Event::assertDispatchedTimes(MessageSent::class, 1);
    Event::assertDispatched(MessageSent::class, fn ($e) => $e->broadcastOn()[0]->name === "presence-conversation.{$c->id}.1");
});

it('SEC-2 non-participants cannot read or post', function () {
    [, $lecturer, $student] = courseWithMembers();
    $c = direct($this, $student, $lecturer);
    $outsider = User::factory()->student()->create();
    $this->actingAs($outsider)->getJson("/api/conversations/{$c->id}/messages")->assertForbidden();
    $this->actingAs($outsider)->getJson("/api/conversations/{$c->id}")->assertForbidden();
    send($this, $outsider, $c)->assertForbidden();
});

it('SEC-6 message bodies are encrypted at rest', function () {
    [, $lecturer, $student] = courseWithMembers();
    $c = direct($this, $student, $lecturer);
    $id = send($this, $student, $c, ['body' => 'secret exam answer'])->json('data.id');
    $raw = DB::table('messages')->where('id', $id)->value('body');
    expect($raw)->not->toContain('secret')->and(Message::find($id)->body)->toBe('secret exam answer');
});

it('MSG-7 ticks go sent → delivered → read and the sender is notified', function () {
    Event::fake([ReceiptsUpdated::class, MessageSent::class]);
    [$course, $lecturer, $student] = courseWithMembers();
    $other = User::factory()->student()->create();
    app(Enrolment::class)->enrol($course, [$other->id], 'student');
    $chat = $course->conversation;
    $id = send($this, $lecturer, $chat)->json('data.id');

    $this->actingAs($student)->postJson('/api/messages/receipts', ['delivered' => [$id]])->assertOk();
    $status = fn () => collect($this->actingAs($lecturer)->getJson("/api/conversations/{$chat->id}/messages")->json('data'))->firstWhere('id', $id)['status'];
    expect($status())->toBe('sent'); // one of two recipients

    $this->actingAs($other)->postJson('/api/messages/receipts', ['delivered' => [$id]]);
    expect($status())->toBe('delivered');
    Event::assertDispatched(ReceiptsUpdated::class, fn ($e) => $e->senderId === $lecturer->id && $e->receipts[0]['status'] === 'delivered');

    $this->actingAs($student)->postJson('/api/messages/receipts', ['read' => [$id]]);
    $this->actingAs($other)->postJson('/api/messages/receipts', ['read' => [$id]]);
    expect($status())->toBe('read');

    $info = $this->actingAs($lecturer)->getJson("/api/messages/{$id}/info")->assertOk();
    expect($info->json('recipients'))->toHaveCount(2)
        ->and($info->json('recipients.0.read_at'))->not->toBeNull()
        ->and($info->json('status'))->toBe('read');
    // others cannot see message info
    $this->actingAs($student)->getJson("/api/messages/{$id}/info")->assertForbidden();
});

it('MSG-1 lists conversations with unread counts and last message preview', function () {
    [, $lecturer, $student] = courseWithMembers();
    $c = direct($this, $student, $lecturer);
    send($this, $lecturer, $c, ['body' => 'one']);
    $last = send($this, $lecturer, $c, ['body' => 'two'])->json('data.id');

    $item = collect($this->actingAs($student)->getJson('/api/conversations')->assertOk()->json('data'))->firstWhere('id', $c->id);
    expect($item['unread_count'])->toBe(2)->and($item['last_message']['preview'])->toBe('two')->and($item['title'])->toBe($lecturer->name);

    $this->actingAs($student)->postJson('/api/messages/receipts', ['read' => [$last]]);
    $item = collect($this->actingAs($student)->getJson('/api/conversations')->json('data'))->firstWhere('id', $c->id);
    expect($item['unread_count'])->toBe(0);
});

it('MSG-3 groups: create, add, remove, leave with system messages', function () {
    Event::fake([ConversationChanged::class]);
    [$course, $lecturer, $student] = courseWithMembers();
    $s2 = User::factory()->student()->create();
    $s3 = User::factory()->student()->create();
    app(Enrolment::class)->enrol($course, [$s2->id, $s3->id], 'student');

    $id = $this->actingAs($student)->postJson('/api/conversations', ['type' => 'group', 'title' => 'Study group', 'user_ids' => [$s2->id]])
        ->assertCreated()->assertJsonPath('data.my_role', 'admin')->json('data.id');
    $c = Conversation::find($id);

    $this->actingAs($student)->postJson("/api/conversations/{$id}/participants", ['user_ids' => [$s3->id]])->assertOk();
    $this->actingAs($s2)->postJson("/api/conversations/{$id}/participants", ['user_ids' => [$lecturer->id]])->assertForbidden();
    $this->actingAs($student)->deleteJson("/api/conversations/{$id}/participants/{$s3->id}")->assertOk();
    $this->actingAs($s2)->postJson("/api/conversations/{$id}/leave")->assertOk();

    $system = $c->messages()->where('type', 'system')->orderBy('id')->get()->pluck('body')->all();
    expect($system[0])->toContain('created the group')
        ->and(implode(' | ', $system))->toContain("added {$s3->name}")->toContain("removed {$s3->name}")->toContain("{$s2->name} left");
    Event::assertDispatched(ConversationChanged::class, fn ($e) => $e->userId === $s3->id && $e->reason === 'removed');
});

it('MSG-4 announcements-only course chats only accept lecturer posts', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $chat = $course->conversation;
    $this->actingAs($student)->patchJson("/api/conversations/{$chat->id}", ['announcements_only' => true])->assertForbidden();
    $this->actingAs($lecturer)->patchJson("/api/conversations/{$chat->id}", ['announcements_only' => true])->assertOk()->assertJsonPath('data.announcements_only', true);
    send($this, $student, $chat)->assertForbidden();
    send($this, $lecturer, $chat)->assertCreated();
});

it('MSG-6 file messages; the file is visible to participants only', function () {
    fakeStorage();
    [, $lecturer, $student] = courseWithMembers();
    $c = direct($this, $student, $lecturer);
    $att = $this->actingAs($student)->post('/api/attachments', ['file' => pdfUpload('slides.pdf')], ['Accept' => 'application/json'])->json('data.id');
    send($this, $student, $c, ['body' => null, 'attachment_id' => $att])->assertCreated()->assertJsonPath('data.type', 'file');

    $this->actingAs($lecturer)->getJson("/api/attachments/{$att}?json=1")->assertOk();
    $this->actingAs(User::factory()->create())->getJson("/api/attachments/{$att}?json=1")->assertForbidden();
});

it('MSG-10 reply with quoted preview and delete for everyone within one hour', function () {
    Event::fake([MessageDeleted::class, MessageSent::class]);
    [, $lecturer, $student] = courseWithMembers();
    $c = direct($this, $student, $lecturer);
    $orig = send($this, $lecturer, $c, ['body' => 'When is the exam?'])->json('data.id');
    send($this, $student, $c, ['body' => 'Friday', 'reply_to_id' => $orig])
        ->assertJsonPath('data.reply_to.id', $orig)->assertJsonPath('data.reply_to.excerpt', 'When is the exam?');

    $this->actingAs($student)->deleteJson("/api/messages/{$orig}")->assertForbidden(); // not mine
    $this->actingAs($lecturer)->deleteJson("/api/messages/{$orig}")->assertOk();
    Event::assertDispatched(MessageDeleted::class);
    $msg = collect($this->actingAs($student)->getJson("/api/conversations/{$c->id}/messages")->json('data'))->firstWhere('id', $orig);
    expect($msg['deleted'])->toBeTrue()->and($msg['body'])->toBeNull();

    $late = send($this, $lecturer, $c)->json('data.id');
    $this->travel(61)->minutes();
    $this->actingAs($lecturer)->deleteJson("/api/messages/{$late}")->assertStatus(422);
});

it('MSG-11 paginates history upward with a cursor', function () {
    [, $lecturer, $student] = courseWithMembers();
    $c = direct($this, $student, $lecturer);
    foreach (range(1, 60) as $i) {
        Message::create(['conversation_id' => $c->id, 'sender_id' => $lecturer->id, 'client_uuid' => (string) Str::uuid(), 'body' => "m{$i}"]);
    }
    $page = $this->actingAs($student)->getJson("/api/conversations/{$c->id}/messages")->assertOk();
    expect($page->json('data'))->toHaveCount(50)->and($page->json('has_more'))->toBeTrue()->and($page->json('data.49.body'))->toBe('m60');
    $older = $this->actingAs($student)->getJson("/api/conversations/{$c->id}/messages?before=".$page->json('next_before'));
    expect($older->json('data'))->toHaveCount(10)->and($older->json('has_more'))->toBeFalse()->and($older->json('data.0.body'))->toBe('m1');
});

it('MSG-12 mutes and unmutes', function () {
    [, $lecturer, $student] = courseWithMembers();
    $c = direct($this, $student, $lecturer);
    $this->actingAs($student)->postJson("/api/conversations/{$c->id}/mute", ['hours' => 8])->assertOk()->assertJsonPath('data.muted_until', fn ($v) => $v !== null);
    $this->actingAs($student)->postJson("/api/conversations/{$c->id}/mute", ['hours' => 0])->assertJsonPath('data.muted_until', null);
});

it('SEC-7 limits sending to 60 messages per minute', function () {
    [, $lecturer, $student] = courseWithMembers();
    $c = direct($this, $student, $lecturer);
    foreach (range(1, 60) as $i) {
        send($this, $student, $c)->assertCreated();
    }
    send($this, $student, $c)->assertStatus(429);
});

it('lists contacts that share a course', function () {
    [, $lecturer, $student] = courseWithMembers();
    User::factory()->student()->create(['name' => 'Stranger']);
    $names = collect($this->actingAs($student)->getJson('/api/contacts')->json('data'))->pluck('name');
    expect($names)->toContain($lecturer->name)->not->toContain('Stranger');
});
