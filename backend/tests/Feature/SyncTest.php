<?php

use App\Models\Assignment;
use App\Models\CourseMaterial;
use App\Models\Message;
use App\Models\Submission;
use App\Models\User;
use App\Support\Enrolment;
use Illuminate\Support\Str;

function sync($test, User $user, ?string $cursor = null)
{
    return $test->actingAs($user)->getJson('/api/sync'.($cursor ? '?since='.urlencode($cursor) : ''))->assertOk();
}

/** Loop like the client does until has_more is false (PRD 9.3). */
function syncAll($test, User $user, ?string $cursor = null): array
{
    $pages = [];
    do {
        $res = sync($test, $user, $cursor)->json();
        $pages[] = $res;
        $cursor = $res['cursor'];
    } while ($res['has_more']);

    return [$pages, $cursor];
}

it('PRD 9.3 first sync returns what the user can see', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    CourseMaterial::factory()->for($course)->create(['title' => 'Visible']);
    CourseMaterial::factory()->for($course)->draft()->create(['title' => 'Draft']);
    CourseMaterial::factory()->create(['title' => 'Other course']);
    $chat = $course->conversation;
    Message::create(['conversation_id' => $chat->id, 'sender_id' => $lecturer->id, 'client_uuid' => (string) Str::uuid(), 'body' => 'hi']);

    $res = sync($this, $student)->json();
    expect(collect($res['materials'])->pluck('title')->all())->toBe(['Visible'])
        ->and($res['courses'][0]['id'])->toBe($course->id)
        ->and($res['courses'][0]['my_role'])->toBe('student')
        ->and(collect($res['conversations'])->pluck('id'))->toContain($chat->id)
        ->and(collect($res['messages'])->pluck('body'))->toContain('hi')
        ->and($res['memberships']['course_ids'])->toBe([$course->id])
        ->and($res['cursor'])->toContain('|')
        ->and($res['has_more'])->toBeFalse();

    // the lecturer also gets drafts
    expect(collect(sync($this, $lecturer)->json('materials'))->pluck('title')->sort()->values()->all())->toBe(['Draft', 'Visible']);
});

it('PRD 9.3 incremental sync returns only changes, deletions and drafts as deleted', function () {
    [$course, , $student] = courseWithMembers();
    $keep = CourseMaterial::factory()->for($course)->create();
    $gone = CourseMaterial::factory()->for($course)->create();
    $hidden = CourseMaterial::factory()->for($course)->create();
    $this->travel(5)->seconds(); // outside the overlap window of the next round
    $cursor = sync($this, $student)->json('cursor');

    $this->travel(5)->seconds();
    $gone->delete();
    $hidden->update(['published_at' => null]);
    $new = CourseMaterial::factory()->for($course)->create(['title' => 'New']);

    $res = sync($this, $student, $cursor)->json();
    expect(collect($res['materials'])->pluck('id')->all())->toBe([$new->id])
        ->and($res['deleted']['materials'])->toEqualCanonicalizing([$gone->id, $hidden->id])
        ->and(collect($res['materials'])->pluck('id'))->not->toContain($keep->id);
});

it('PRD 9.3 un-enrolment drops the course and its chat through memberships', function () {
    [$course, , $student] = courseWithMembers();
    $cursor = sync($this, $student)->json('cursor');
    app(Enrolment::class)->unenrol($course, [$student->id]);
    $res = sync($this, $student, $cursor)->json();
    expect($res['memberships']['course_ids'])->toBe([])
        ->and($res['memberships']['conversation_ids'])->not->toContain($course->conversation->id);
});

it('PRD 9.3 pages through many messages with has_more, without gaps or duplicates', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $cursor = sync($this, $student)->json('cursor');
    $this->travel(5)->seconds();
    $chat = $course->conversation;
    $rows = [];
    foreach (range(1, 1100) as $i) {
        $rows[] = ['conversation_id' => $chat->id, 'sender_id' => $lecturer->id, 'client_uuid' => (string) Str::uuid(), 'type' => 'text', 'body' => encrypt("m{$i}", false), 'created_at' => now(), 'updated_at' => now()];
    }
    Message::insert($rows); // same timestamp for all: the id tie-breaker keeps the order

    [$pages] = syncAll($this, $student, $cursor);
    $ids = collect($pages)->flatMap(fn ($p) => collect($p['messages'])->pluck('id'));
    expect(count($pages))->toBe(3)
        ->and($ids->count())->toBe(1100)
        ->and($ids->unique()->count())->toBe(1100)
        ->and($ids->all())->toBe($ids->sort()->values()->all());
});

it('PRD 9.3 deleted messages are reported so clients show the placeholder', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $m = Message::create(['conversation_id' => $course->conversation->id, 'sender_id' => $lecturer->id, 'client_uuid' => (string) Str::uuid(), 'body' => 'oops']);
    $cursor = sync($this, $student)->json('cursor');
    $this->travel(5)->seconds();
    $this->actingAs($lecturer)->deleteJson("/api/messages/{$m->id}")->assertOk();
    $res = sync($this, $student, $cursor)->json();
    expect($res['deleted']['messages'])->toBe([$m->id])
        ->and(collect($res['messages'])->firstWhere('id', $m->id)['body'])->toBeNull();
});

it('PRD 9.3 students only receive released grades', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->create();
    $sub = Submission::factory()->for($a)->create(['student_id' => $student->id]);
    $this->actingAs($lecturer)->patchJson("/api/submissions/{$sub->id}/grade", ['score' => 70]);
    $cursor = sync($this, $student)->json('cursor');
    expect(sync($this, $student)->json('grades'))->toBe([]);

    $this->travel(5)->seconds();
    $this->actingAs($lecturer)->postJson("/api/grade-items/{$a->gradeItem->id}/release");
    $grades = sync($this, $student, $cursor)->json('grades');
    expect($grades)->toHaveCount(1)->and($grades[0]['score'])->toEqual(70);
});

it('NFR-6 / MOB-5 replaying 50 offline messages after reconnect creates no duplicates and keeps order', function () {
    [$course, , $student] = courseWithMembers();
    $chat = $course->conversation;
    $uuids = collect(range(1, 50))->map(fn () => (string) Str::uuid());
    // the outbox sends in order; a flaky network makes it retry some of them
    foreach ($uuids as $i => $uuid) {
        $this->actingAs($student)->postJson("/api/conversations/{$chat->id}/messages", ['client_uuid' => $uuid, 'body' => "offline {$i}"])->assertCreated();
        if ($i % 7 === 0) {
            $this->actingAs($student)->postJson("/api/conversations/{$chat->id}/messages", ['client_uuid' => $uuid, 'body' => "offline {$i}"])->assertOk();
        }
    }
    $stored = Message::where('conversation_id', $chat->id)->where('sender_id', $student->id)->orderBy('id')->get();
    expect($stored)->toHaveCount(50)
        ->and($stored->pluck('client_uuid')->all())->toBe($uuids->all())
        ->and($stored->pluck('body')->all())->toBe($uuids->keys()->map(fn ($i) => "offline {$i}")->all());
});

it('sync is scoped to the user', function () {
    [$course] = courseWithMembers();
    CourseMaterial::factory()->for($course)->create();
    $outsider = User::factory()->student()->create();
    $res = sync($this, $outsider)->json();
    expect($res['courses'])->toBe([])->and($res['materials'])->toBe([])->and($res['messages'])->toBe([]);
});
