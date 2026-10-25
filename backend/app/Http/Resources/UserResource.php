<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Files\FileUrlSigner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $self = $viewer && $viewer->id === $this->id;
        $admin = $viewer?->isAdmin();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->when($self || $admin || $viewer?->isLecturer(), $this->email),
            'role' => $this->role->value,
            'student_number' => $this->when($self || $admin || $viewer?->isLecturer(), $this->student_number),
            'avatar_url' => $this->avatar_path ? app(FileUrlSigner::class)->temporaryUrl($this->avatar_path, minutes: 60) : null,
            'last_seen_at' => $this->last_seen_at?->toIso8601ZuluString(),
            'is_active' => $this->when($self || $admin, $this->is_active),
            'created_at' => $this->when($admin, fn () => $this->created_at?->toIso8601ZuluString()),
        ];
    }
}
