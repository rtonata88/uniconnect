<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attachment */
class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size_bytes,
            'is_image' => $this->isImage(),
            'sha256' => $this->sha256,
            // GET → 302 to a 5-minute signed URL; `?json=1` returns {url, expires_at}
            'url' => '/api/attachments/'.$this->id,
        ];
    }
}
