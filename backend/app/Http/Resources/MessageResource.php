<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin Message */
class MessageResource extends JsonResource
{
    /** @var array{status:string, delivered:int, read:int, recipients:int}|null tick status (sender's own messages) */
    public ?array $tick = null;

    public function withStatus(?array $tick): static
    {
        $this->tick = $tick;

        return $this;
    }

    /**
     * @param  iterable<Message>  $messages
     * @param  array<int, array>  $statuses
     */
    public static function list(iterable $messages, array $statuses): array
    {
        $out = [];
        foreach ($messages as $m) {
            $out[] = (new self($m))->withStatus($statuses[$m->id] ?? null);
        }

        return $out;
    }

    public function toArray(Request $request): array
    {
        $deleted = $this->deleted_at !== null;
        $reply = $this->relationLoaded('replyTo') ? $this->replyTo : null;
        $status = $this->tick;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'client_uuid' => $this->client_uuid,
            'type' => $this->type,
            'sender' => $this->sender ? ['id' => $this->sender->id, 'name' => $this->sender->name] : null,
            // MSG-10: deleted messages keep their place but lose their content
            'body' => $deleted ? null : $this->body,
            'attachment' => ! $deleted && $this->attachment ? new AttachmentResource($this->attachment) : null,
            'reply_to' => $reply ? [
                'id' => $reply->id,
                'sender_name' => $reply->sender?->name,
                'type' => $reply->type,
                'excerpt' => $reply->deleted_at ? null : Str::limit((string) ($reply->body ?: $reply->attachment?->original_name), 120),
            ] : null,
            'deleted' => $deleted,
            'status' => $status['status'] ?? null,
            'read_count' => $status['read'] ?? null,
            'recipient_count' => $status['recipients'] ?? null,
            'created_at' => $this->created_at->toIso8601ZuluString('millisecond'),
            'updated_at' => $this->updated_at->toIso8601ZuluString('microsecond'),
        ];
    }
}
