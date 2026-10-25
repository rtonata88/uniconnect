<?php

namespace App\Policies;

use App\Models\GradeItem;
use App\Models\User;

class GradeItemPolicy
{
    public function manage(User $user, GradeItem $item): bool
    {
        return $item->course->isLecturer($user);
    }
}
