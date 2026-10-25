<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** The signed-in user (AUTH-4). Enrolled courses are added once courses exist. */
class MeResource extends UserResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'email' => $this->email,
            'student_number' => $this->student_number,
            'is_active' => $this->is_active,
            'notification_preferences' => $this->notification_preferences ?? (object) [],
        ]);
    }
}
