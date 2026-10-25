<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared rules for publishable course content (materials, announcements, assignments, quizzes):
 * members see published items, course lecturers see and manage everything.
 */
trait CourseContentPolicy
{
    public function view(User $user, Model $item): bool
    {
        $course = $item->course;
        if ($course->isLecturer($user)) {
            return true;
        }

        return ($user->isAdmin() || $course->isMember($user)) && $item->isPublished();
    }

    public function update(User $user, Model $item): bool
    {
        return $item->course->isLecturer($user);
    }

    public function delete(User $user, Model $item): bool
    {
        return $item->course->isLecturer($user);
    }
}
