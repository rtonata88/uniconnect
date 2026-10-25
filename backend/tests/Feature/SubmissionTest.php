<?php

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(fn () => fakeStorage());

function submit($test, User $student, Assignment $a, array $extra = [])
{
    return $test->actingAs($student)->postJson("/api/assignments/{$a->id}/submissions", array_merge([
        'client_uuid' => (string) Str::uuid(), 'text' => 'My answer',
    ], $extra));
}

it('LMS-5 lecturer creates an assignment with due date and late policy', function () {
    [$course, $lecturer] = courseWithMembers();
    $this->actingAs($lecturer)->postJson("/api/courses/{$course->id}/assignments", [
        'title' => 'Lab 1', 'due_at' => now()->addWeek()->toIso8601String(), 'max_score' => 50, 'allow_late' => true, 'late_penalty_percent' => 10,
    ])->assertCreated()->assertJsonPath('data.max_score', 50)->assertJsonPath('data.late_penalty_percent', 10);
});

it('LMS-6 student submits text before the due date', function () {
    [$course, , $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->create();
    submit($this, $student, $a)->assertCreated()->assertJsonPath('data.is_late', false)->assertJsonPath('data.attempt', 1);
    $this->actingAs($student)->getJson("/api/assignments/{$a->id}/my-submission")->assertJsonPath('data.text', 'My answer');
});

it('MOB-5 / PRD 9.2 a replayed submission with the same client_uuid is not duplicated', function () {
    [$course, , $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->create();
    $uuid = (string) Str::uuid();
    submit($this, $student, $a, ['client_uuid' => $uuid])->assertCreated();
    submit($this, $student, $a, ['client_uuid' => $uuid])->assertOk()->assertJsonPath('data.client_uuid', $uuid);
    expect(Submission::count())->toBe(1);
});

it('LMS-6 resubmission before the due date replaces the previous one', function () {
    [$course, , $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->create();
    submit($this, $student, $a, ['text' => 'v1']);
    submit($this, $student, $a, ['text' => 'v2'])->assertCreated()->assertJsonPath('data.attempt', 2)->assertJsonPath('data.text', 'v2');
    expect(Submission::count())->toBe(1);
});

it('LMS-6 rejects submissions after the due date when late work is not allowed', function () {
    [$course, , $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->pastDue()->create();
    submit($this, $student, $a)->assertStatus(422);
});

it('LMS-6 accepts late submissions when allowed, marks them late (server time) and audits the client time', function () {
    [$course, , $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->pastDue(allowLate: true, penalty: 20)->create();
    $clientTime = now()->subDays(2)->toIso8601String(); // device clock claims it was on time
    submit($this, $student, $a, ['client_submitted_at' => $clientTime])->assertCreated()->assertJsonPath('data.is_late', true);

    $log = AuditLog::where('action', 'submission.submitted')->first();
    expect($log->meta['client_submitted_at'])->toBe($clientTime)->and($log->meta['is_late'])->toBeTrue();
});

it('LMS-6 submits a file', function () {
    [$course, , $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->create();
    $att = $this->actingAs($student)->post('/api/attachments', ['file' => pdfUpload('essay.pdf')], ['Accept' => 'application/json'])->json('data.id');
    submit($this, $student, $a, ['text' => null, 'attachment_id' => $att])->assertCreated()->assertJsonPath('data.attachment.name', 'essay.pdf');
});

it('SEC-2 lecturers cannot submit and outsiders cannot see the assignment', function () {
    [$course, $lecturer] = courseWithMembers();
    $a = Assignment::factory()->for($course)->create();
    submit($this, $lecturer, $a)->assertForbidden();
    submit($this, User::factory()->student()->create(), $a)->assertForbidden();
});

it('LMS-7 lecturer sees on time / late / missing per student', function () {
    [$course, $lecturer, $s1] = courseWithMembers();
    $s2 = User::factory()->student()->create();
    $s3 = User::factory()->student()->create();
    $course->members()->attach([$s2->id => ['role' => 'student'], $s3->id => ['role' => 'student']]);
    $a = Assignment::factory()->for($course)->pastDue(allowLate: true)->create();
    Submission::factory()->for($a)->create(['student_id' => $s1->id, 'is_late' => false]);
    Submission::factory()->for($a)->create(['student_id' => $s2->id, 'is_late' => true]);

    $this->actingAs($lecturer)->getJson("/api/assignments/{$a->id}/submissions")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('summary', ['on_time' => 1, 'late' => 1, 'missing' => 1, 'not_submitted' => 0, 'graded' => 0]);
});

it('LMS-7 lecturer grades with feedback; grading is audited', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->create(['max_score' => 50]);
    $sub = Submission::factory()->for($a)->create(['student_id' => $student->id]);

    $this->actingAs($lecturer)->patchJson("/api/submissions/{$sub->id}/grade", ['score' => 42, 'feedback' => 'Good'])
        ->assertOk()->assertJsonPath('data.score', 42)->assertJsonPath('data.is_graded', true);
    $this->actingAs($lecturer)->patchJson("/api/submissions/{$sub->id}/grade", ['score' => 51])->assertStatus(422);
    expect(AuditLog::where('action', 'grade.changed')->count())->toBe(1);
});

it('SEC-2 a student cannot grade', function () {
    [$course, , $student] = courseWithMembers();
    $sub = Submission::factory()->for(Assignment::factory()->for($course))->create(['student_id' => $student->id]);
    $this->actingAs($student)->patchJson("/api/submissions/{$sub->id}/grade", ['score' => 100])->assertForbidden();
});

it('LMS-10 students do not see their score before release', function () {
    [$course, , $student] = courseWithMembers();
    $sub = Submission::factory()->for(Assignment::factory()->for($course))->create(['student_id' => $student->id, 'score' => 80, 'graded_at' => now()]);
    $this->actingAs($student)->getJson("/api/assignments/{$sub->assignment_id}/my-submission")
        ->assertJsonPath('data.is_graded', true)->assertJsonPath('data.score', null);
});

it('applies the late penalty to the final score', function () {
    $sub = Submission::factory()->for(Assignment::factory()->pastDue(true, 20))->create(['is_late' => true, 'score' => 50]);
    expect($sub->fresh()->finalScore())->toBe(40.0);
});

it('rejects resubmission once the submission has been graded', function () {
    [$course, , $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->create();
    $sub = Submission::factory()->for($a)->create(['student_id' => $student->id, 'score' => 70, 'graded_at' => now()]);
    submit($this, $student, $a, ['text' => 'new'])->assertStatus(409);
    expect($sub->fresh()->score)->toBe(70.0);
});
