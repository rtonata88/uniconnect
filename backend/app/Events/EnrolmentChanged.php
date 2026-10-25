<?php

namespace App\Events;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** $role is null when the user was removed from the course. */
class EnrolmentChanged
{
    use Dispatchable;

    public function __construct(public Course $course, public User $user, public ?string $role) {}
}
