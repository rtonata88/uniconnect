<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** The signed-in user with role and enrolled courses (AUTH-4). */
class MeResource extends UserResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'email' => $this->email,
            'student_number' => $this->student_number,
            'is_active' => $this->is_active,
            'notification_preferences' => $this->notification_preferences ?? (object) [],
            'courses' => $this->courses()->orderBy('code')->get()->map(fn ($c) => [
                'id' => $c->id, 'code' => $c->code, 'title' => $c->title, 'role' => $c->pivot->role,
            ]),
        ]);
    }
}
