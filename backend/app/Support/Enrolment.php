<?php

namespace App\Support;

use App\Models\Course;
use App\Models\User;

/**
 * Enrolment changes (LMS-1). Every change is audited (SEC-9) and fires an event
 * so the course chat membership can follow (PRD §6 rules).
 */
class Enrolment
{
    /**
     * @param  list<int>  $userIds
     * @return list<int> users whose enrolment changed
     */
    public function enrol(Course $course, array $userIds, string $role): array
    {
        $changed = [];
        $existing = $course->members()->pluck('course_user.role', 'users.id');
        foreach (User::whereIn('id', $userIds)->get() as $user) {
            if (($existing[$user->id] ?? null) === $role) {
                continue;
            }
            $course->members()->syncWithoutDetaching([$user->id => ['role' => $role]]);
            $changed[] = $user->id;
            Audit::log('enrolment.added', $course, ['user_id' => $user->id, 'role' => $role]);
            event(new \App\Events\EnrolmentChanged($course, $user, $role));
        }
        if ($changed) {
            $course->touch();
        }

        return $changed;
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function unenrol(Course $course, array $userIds): array
    {
        $removed = $course->members()->whereIn('users.id', $userIds)->pluck('users.id')->all();
        if (! $removed) {
            return [];
        }
        $course->members()->detach($removed);
        foreach (User::whereIn('id', $removed)->get() as $user) {
            Audit::log('enrolment.removed', $course, ['user_id' => $user->id]);
            event(new \App\Events\EnrolmentChanged($course, $user, null));
        }
        $course->touch();

        return $removed;
    }
}
