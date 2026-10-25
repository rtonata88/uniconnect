<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Support\Files\FileUrlSigner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin Conversation */
class ConversationResource extends JsonResource
{
    public bool $withParticipants = false;

    public function withParticipants(): static
    {
        $this->withParticipants = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $me = $request->user();
        $participants = $this->relationLoaded('participants') ? $this->participants : collect();
        $mine = $participants->firstWhere('user_id', $me?->id);
        $other = $this->type === 'direct' ? $participants->firstWhere('user_id', '!=', $me?->id)?->user : null;
        $last = $this->relationLoaded('lastMessage') ? $this->lastMessage : null;
        $signer = app(FileUrlSigner::class);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->type === 'direct' ? ($other?->name ?? 'Deleted user') : $this->title,
            'course_id' => $this->course_id,
            'announcements_only' => $this->announcements_only,
            'channel' => $this->channelName(),
            'avatar_url' => $other?->avatar_path ? $signer->temporaryUrl($other->avatar_path, minutes: 60) : null,
            'other_user' => $other ? ['id' => $other->id, 'name' => $other->name, 'last_seen_at' => $other->last_seen_at?->toIso8601ZuluString()] : null,
            'my_role' => $mine?->role,
            'can_post' => ! $this->announcements_only || $mine?->role === 'admin',
            'muted_until' => $mine?->muted_until?->toIso8601ZuluString(),
            'last_read_message_id' => $mine?->last_read_message_id,
            'unread_count' => (int) ($this->unread_count ?? 0),
            'participant_count' => $participants->count(),
            'participants' => $this->when($this->withParticipants, fn () => $participants->map(fn ($p) => [
                'id' => $p->user_id,
                'name' => $p->user?->name,
                'role' => $p->role,
                'avatar_url' => $p->user?->avatar_path ? $signer->temporaryUrl($p->user->avatar_path, minutes: 60) : null,
                'last_seen_at' => $p->user?->last_seen_at?->toIso8601ZuluString(),
            ])->sortBy('name')->values()),
            'last_message' => $last ? [
                'id' => $last->id,
                'type' => $last->type,
                'sender_id' => $last->sender_id,
                'sender_name' => $last->sender?->name,
                'preview' => $last->deleted_at ? 'This message was deleted' : Str::limit((string) ($last->body ?: ($last->type === 'image' ? 'Photo' : $last->attachment?->original_name)), 80),
                'created_at' => $last->created_at->toIso8601ZuluString('millisecond'),
            ] : null,
            'last_message_at' => ($this->last_message_at ?? $this->created_at)->toIso8601ZuluString('millisecond'),
            'updated_at' => $this->updated_at->toIso8601ZuluString('microsecond'),
        ];
    }
}
