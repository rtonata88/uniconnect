<?php

use App\Models\Course;
use App\Models\User;

it('lists only the courses the user is enrolled in', function () {
    [$course, , $student] = courseWithMembers();
    Course::factory()->create();

    $this->actingAs($student)->getJson('/api/courses')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $course->id)
        ->assertJsonPath('data.0.my_role', 'student');
});

it('admins see every course', function () {
    Course::factory()->count(3)->create();
    $this->actingAs(User::factory()->admin()->create())->getJson('/api/courses')->assertJsonCount(3, 'data');
});

it('E2E-4 / SEC-2 a student cannot open a course they are not enrolled in', function () {
    [$course] = courseWithMembers();
    $outsider = User::factory()->student()->create();

    $this->actingAs($outsider)->getJson("/api/courses/{$course->id}")->assertForbidden()->assertJsonStructure(['message']);
    $this->actingAs($outsider)->getJson("/api/courses/{$course->id}/materials")->assertForbidden();
    $this->actingAs($outsider)->getJson("/api/courses/{$course->id}/announcements")->assertForbidden();
    $this->actingAs($outsider)->getJson("/api/courses/{$course->id}/assignments")->assertForbidden();
    $this->actingAs($outsider)->getJson("/api/courses/{$course->id}/people")->assertForbidden();
});

it('lists course people split by role without leaking emails to students', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $this->actingAs($student)->getJson("/api/courses/{$course->id}/people")
        ->assertOk()
        ->assertJsonPath('lecturers.0.id', $lecturer->id)
        ->assertJsonPath('students.0.id', $student->id)
        ->assertJsonMissingPath('lecturers.0.email');
});

it('AUTH-4 /me includes enrolled courses with role', function () {
    [$course, $lecturer] = courseWithMembers();
    $this->actingAs($lecturer)->getJson('/api/me')
        ->assertJsonPath('data.courses.0.id', $course->id)
        ->assertJsonPath('data.courses.0.role', 'lecturer');
});
