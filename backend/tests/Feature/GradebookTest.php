<?php

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\GradeItem;
use App\Models\Submission;
use App\Models\User;

beforeEach(fn () => fakeStorage());

function gradedAssignment($test, $course, $lecturer, $student, float $score, array $attrs = []): Assignment
{
    $a = Assignment::factory()->for($course)->create(array_merge(['max_score' => 50], $attrs));
    $sub = Submission::factory()->for($a)->create(['student_id' => $student->id]);
    $test->actingAs($lecturer)->patchJson("/api/submissions/{$sub->id}/grade", ['score' => $score])->assertOk();

    return $a;
}

it('LMS-10 assignments get a grade item and grading fills the gradebook', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $a = gradedAssignment($this, $course, $lecturer, $student, 40);

    $res = $this->actingAs($lecturer)->getJson("/api/courses/{$course->id}/gradebook")->assertOk();
    $itemId = $a->gradeItem->id;
    expect($res->json('items.0.source_type'))->toBe('assignment')
        ->and($res->json("rows.0.grades.{$itemId}.score"))->toEqual(40)
        ->and($res->json('rows.0.weighted_total'))->toEqual(80);
});

it('LMS-10 weighted total uses the item weights', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $a1 = gradedAssignment($this, $course, $lecturer, $student, 50); // 100%
    $a2 = gradedAssignment($this, $course, $lecturer, $student, 25); // 50%
    $this->actingAs($lecturer)->patchJson("/api/grade-items/{$a1->gradeItem->id}", ['weight_percent' => 30])->assertOk();
    $this->actingAs($lecturer)->patchJson("/api/grade-items/{$a2->gradeItem->id}", ['weight_percent' => 70])->assertOk();

    $this->actingAs($lecturer)->getJson("/api/courses/{$course->id}/gradebook")->assertJsonPath('rows.0.weighted_total', 65);
});

it('LMS-10 late penalty flows into the gradebook', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->pastDue(true, 10)->create(['max_score' => 100]);
    $sub = Submission::factory()->for($a)->create(['student_id' => $student->id, 'is_late' => true]);
    $this->actingAs($lecturer)->patchJson("/api/submissions/{$sub->id}/grade", ['score' => 80]);
    expect($a->gradeItem->grades()->value('score'))->toBe(72.0);
});

it('LMS-10 students only see released grades', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $a = gradedAssignment($this, $course, $lecturer, $student, 40);

    $this->actingAs($student)->getJson("/api/courses/{$course->id}/my-grades")->assertJsonCount(0, 'items')->assertJsonPath('weighted_total', null);
    $this->actingAs($student)->getJson("/api/assignments/{$a->id}/my-submission")->assertJsonPath('data.score', null);

    $this->actingAs($lecturer)->postJson("/api/grade-items/{$a->gradeItem->id}/release")->assertOk()->assertJsonPath('data.released_at', fn ($v) => $v !== null);

    $this->actingAs($student)->getJson("/api/courses/{$course->id}/my-grades")
        ->assertJsonCount(1, 'items')->assertJsonPath('items.0.grade.score', 40)->assertJsonPath('weighted_total', 80);
    $this->actingAs($student)->getJson("/api/assignments/{$a->id}/my-submission")->assertJsonPath('data.score', 40);
    expect(AuditLog::where('action', 'grade.released')->count())->toBe(1);
});

it('SEC-2 students cannot see the gradebook, release grades or set grades', function () {
    [$course, , $student] = courseWithMembers();
    $item = GradeItem::create(['course_id' => $course->id, 'title' => 'Participation', 'source_type' => 'manual', 'max_score' => 10]);
    $this->actingAs($student)->getJson("/api/courses/{$course->id}/gradebook")->assertForbidden();
    $this->actingAs($student)->postJson("/api/grade-items/{$item->id}/release")->assertForbidden();
    $this->actingAs($student)->putJson("/api/grade-items/{$item->id}/grades", ['student_id' => $student->id, 'score' => 10])->assertForbidden();
});

it('LMS-10 manual grade items', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $id = $this->actingAs($lecturer)->postJson("/api/courses/{$course->id}/grade-items", ['title' => 'Participation', 'max_score' => 10, 'weight_percent' => 10])
        ->assertCreated()->json('data.id');
    $this->actingAs($lecturer)->putJson("/api/grade-items/{$id}/grades", ['student_id' => $student->id, 'score' => 9, 'comment' => 'Active'])
        ->assertOk()->assertJsonPath('data.score', 9);
    $this->actingAs($lecturer)->putJson("/api/grade-items/{$id}/grades", ['student_id' => User::factory()->create()->id, 'score' => 9])->assertStatus(422);
});

it('LMS-10 exports the gradebook as CSV', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    gradedAssignment($this, $course, $lecturer, $student, 40);
    $csv = $this->actingAs($lecturer)->get("/api/courses/{$course->id}/gradebook/export")->assertOk()->streamedContent();
    expect($csv)->toContain('Weighted total %')->toContain($student->name)->toContain('40');
});
