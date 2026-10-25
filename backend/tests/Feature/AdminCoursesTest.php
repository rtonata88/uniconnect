<?php

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\User;

beforeEach(fn () => $this->admin = User::factory()->admin()->create());

it('LMS-1 admin creates a course with lecturers', function () {
    $lecturer = User::factory()->lecturer()->create();
    $this->actingAs($this->admin)->postJson('/api/admin/courses', [
        'code' => 'dms901', 'title' => 'Distributed and Mobile Systems', 'lecturer_ids' => [$lecturer->id],
    ])->assertCreated()->assertJsonPath('data.code', 'DMS901')->assertJsonPath('data.lecturers.0.id', $lecturer->id);

    expect(Course::first()->isLecturer($lecturer))->toBeTrue();
});

it('LMS-1 only users with the lecturer role can be course lecturers', function () {
    $student = User::factory()->student()->create();
    $this->actingAs($this->admin)->postJson('/api/admin/courses', ['code' => 'X1', 'title' => 'X', 'lecturer_ids' => [$student->id]])
        ->assertStatus(422);
    $course = Course::factory()->create();
    $this->actingAs($this->admin)->postJson("/api/admin/courses/{$course->id}/enrolments", ['user_ids' => [$student->id], 'role' => 'lecturer'])
        ->assertStatus(422);
});

it('LMS-1 / SEC-9 enrols and removes students with audit entries', function () {
    $course = Course::factory()->create();
    $students = User::factory()->student()->count(3)->create();

    $this->actingAs($this->admin)->postJson("/api/admin/courses/{$course->id}/enrolments", [
        'user_ids' => $students->pluck('id'), 'role' => 'student',
    ])->assertOk()->assertJsonCount(3, 'added');
    expect($course->students()->count())->toBe(3);

    $this->actingAs($this->admin)->deleteJson("/api/admin/courses/{$course->id}/enrolments", ['user_ids' => [$students[0]->id]])
        ->assertOk()->assertJsonPath('removed', [$students[0]->id]);

    expect($course->students()->count())->toBe(2)
        ->and(AuditLog::where('action', 'enrolment.added')->count())->toBe(3)
        ->and(AuditLog::where('action', 'enrolment.removed')->count())->toBe(1);
});

it('non-admins cannot manage courses', function () {
    $course = Course::factory()->create();
    $lecturer = User::factory()->lecturer()->create();
    $this->actingAs($lecturer)->postJson('/api/admin/courses', ['code' => 'X', 'title' => 'X'])->assertForbidden();
    $this->actingAs($lecturer)->postJson("/api/admin/courses/{$course->id}/enrolments", ['user_ids' => [$lecturer->id], 'role' => 'lecturer'])->assertForbidden();
});

it('soft-deletes a course', function () {
    $course = Course::factory()->create();
    $this->actingAs($this->admin)->deleteJson("/api/admin/courses/{$course->id}")->assertOk();
    expect(Course::withTrashed()->find($course->id)->trashed())->toBeTrue();
});
