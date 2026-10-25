<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['type', 'title', 'course_id', 'direct_key', 'avatar_path', 'created_by', 'last_message_id', 'last_message_at', 'announcements_only'])]
class Conversation extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'announcements_only' => 'boolean'];
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot(['role', 'joined_at', 'last_delivered_message_id', 'last_read_message_id', 'muted_until'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id')->withTrashed();
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function participant(User|int $user): ?ConversationParticipant
    {
        $id = $user instanceof User ? $user->id : $user;
        if ($this->relationLoaded('participants')) {
            return $this->participants->firstWhere('user_id', $id);
        }

        return $this->participants()->where('user_id', $id)->first();
    }

    public function hasParticipant(User|int $user): bool
    {
        return $this->participant($user) !== null;
    }

    public function isAdmin(User $user): bool
    {
        return $this->participant($user)?->role === 'admin';
    }
}
