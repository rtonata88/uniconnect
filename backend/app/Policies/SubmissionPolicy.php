<?php

namespace App\Policies;

use App\Models\Submission;
use App\Models\User;

class SubmissionPolicy
{
    public function view(User $user, Submission $submission): bool
    {
        return $submission->student_id === $user->id || $submission->assignment->course->isLecturer($user);
    }

    public function grade(User $user, Submission $submission): bool
    {
        return $submission->assignment->course->isLecturer($user);
    }
}
