<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Announcement */
class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'client_uuid' => $this->client_uuid,
            'title' => $this->title,
            'body' => $this->body,
            'author' => $this->author ? ['id' => $this->author->id, 'name' => $this->author->name] : null,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString('microsecond'),
        ];
    }
}
