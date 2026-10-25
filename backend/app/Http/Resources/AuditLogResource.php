<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email] : null,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'meta' => $this->meta,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
