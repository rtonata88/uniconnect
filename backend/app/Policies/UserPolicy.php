<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->isAdmin();
    }

    /** Admins cannot deactivate or demote themselves (avoids locking everyone out). */
    public function changeAccess(User $actor, User $user): bool
    {
        return $actor->isAdmin() && $actor->id !== $user->id;
    }
}
