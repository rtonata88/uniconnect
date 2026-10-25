<?php

namespace App\Policies;

use App\Models\QuizAttempt;
use App\Models\User;

class QuizAttemptPolicy
{
    public function view(User $user, QuizAttempt $attempt): bool
    {
        return $attempt->student_id === $user->id || $attempt->quiz->course->isLecturer($user);
    }

    public function answer(User $user, QuizAttempt $attempt): bool
    {
        return $attempt->student_id === $user->id;
    }

    public function grade(User $user, QuizAttempt $attempt): bool
    {
        return $attempt->quiz->course->isLecturer($user);
    }
}
