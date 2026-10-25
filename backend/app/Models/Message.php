<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['conversation_id', 'sender_id', 'client_uuid', 'type', 'body', 'attachment_id', 'reply_to_id', 'edited_at'])]
class Message extends Model
{
    use HasFactory, SoftDeletes;

    /** MSG-10: delete for everyone within this window. */
    public const DELETE_WINDOW_MINUTES = 60;

    protected function casts(): array
    {
        return [
            'body' => 'encrypted', // SEC-6: encrypted at rest with APP_KEY
            'edited_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id')->withTrashed();
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(MessageReceipt::class);
    }
}
