<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

/**
 * SEC-2: course data requires enrolment; lecturer actions require lecturer
 * enrolment in that course. Admins can view every course and manage the
 * course record and enrolments, but only lecturers manage course content.
 */
class CoursePolicy
{
    public function view(User $user, Course $course): bool
    {
        return $user->isAdmin() || $course->isMember($user);
    }

    /** Upload materials, post announcements, create assignments/quizzes, grade. */
    public function teach(User $user, Course $course): bool
    {
        return $course->isLecturer($user);
    }

    public function submit(User $user, Course $course): bool
    {
        return $course->roleOf($user) === 'student';
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Course $course): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Course $course): bool
    {
        return $user->isAdmin();
    }

    public function enrol(User $user, Course $course): bool
    {
        return $user->isAdmin();
    }
}
