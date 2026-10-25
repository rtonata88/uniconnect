<?php

namespace App\Support\Chat;

use App\Events\ConversationChanged;
use App\Events\MessageSent;
use App\Events\ReceiptsUpdated;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReceipt;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Messaging core (PRD 5.3, MSG-*). Controllers stay thin; this class owns
 * participants, idempotent sending, receipts and tick status.
 */
class ChatService
{
    /** @param  iterable<User>  $users */
    public function addParticipants(Conversation $conversation, iterable $users, string $role = 'member', ?User $actor = null, bool $announce = true): array
    {
        $added = [];
        foreach ($users as $user) {
            $existing = $conversation->participants()->where('user_id', $user->id)->first();
            if ($existing) {
                if ($existing->role !== $role && $conversation->type === 'course') {
                    $existing->update(['role' => $role]); // e.g. student → lecturer
                }

                continue;
            }
            $conversation->participants()->create([
                'user_id' => $user->id,
                'role' => $role,
                'joined_at' => now(),
                // history before joining counts as read
                'last_read_message_id' => $conversation->last_message_id ?? 0,
                'last_delivered_message_id' => $conversation->last_message_id ?? 0,
            ]);
            $added[] = $user;
        }
        if ($added) {
            $conversation->touch();
            if ($announce && $conversation->type === 'group') {
                $names = collect($added)->pluck('name')->join(', ', ' and ');
                $this->systemMessage($conversation, $actor ? "{$actor->name} added {$names}" : "{$names} joined");
            }
            foreach ($added as $user) {
                ConversationChanged::dispatch($user->id, $conversation->id, 'created');
            }
            $this->notifyMembers($conversation, 'updated', except: collect($added)->pluck('id')->all());
        }

        return $added;
    }

    public function removeParticipant(Conversation $conversation, User $user, ?User $actor = null): bool
    {
        $deleted = $conversation->participants()->where('user_id', $user->id)->delete() > 0;
        if (! $deleted) {
            return false;
        }
        $conversation->touch();
        if ($conversation->type === 'group') {
            $this->systemMessage($conversation, $actor && $actor->id !== $user->id ? "{$actor->name} removed {$user->name}" : "{$user->name} left");
            // the group keeps working without admins: promote the oldest member
            if (! $conversation->participants()->where('role', 'admin')->exists()) {
                $conversation->participants()->orderBy('joined_at')->first()?->update(['role' => 'admin']);
            }
        }
        ConversationChanged::dispatch($user->id, $conversation->id, 'removed');
        $this->notifyMembers($conversation, 'updated');

        return true;
    }

    public function notifyMembers(Conversation $conversation, string $reason, array $except = []): void
    {
        $conversation->participants()->whereNotIn('user_id', $except)->pluck('user_id')
            ->each(fn ($uid) => ConversationChanged::dispatch($uid, $conversation->id, $reason));
    }

    public function systemMessage(Conversation $conversation, string $text): Message
    {
        $message = $conversation->messages()->create([
            'sender_id' => null,
            'client_uuid' => (string) Str::uuid(),
            'type' => 'system',
            'body' => $text,
        ]);
        $this->bump($conversation, $message);
        MessageSent::dispatch($message->load('conversation'));

        return $message;
    }

    /**
     * Idempotent send (PRD 9.2): the same client_uuid returns the stored message.
     *
     * @return array{0: Message, 1: bool} message and whether it was created now
     */
    public function send(Conversation $conversation, User $sender, array $data): array
    {
        if ($existing = Message::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            if ($existing->sender_id !== $sender->id || $existing->conversation_id !== $conversation->id) {
                throw ValidationException::withMessages(['client_uuid' => 'client_uuid already used.']);
            }

            return [$existing, false];
        }

        $attachment = null;
        if (! empty($data['attachment_id'])) {
            $attachment = Attachment::find($data['attachment_id']);
            if (! $attachment || $attachment->owner_id !== $sender->id) {
                throw ValidationException::withMessages(['attachment_id' => 'Unknown attachment.']);
            }
        }
        if (! empty($data['reply_to_id']) && ! $conversation->messages()->whereKey($data['reply_to_id'])->exists()) {
            throw ValidationException::withMessages(['reply_to_id' => 'You can only reply to a message in this chat.']);
        }

        try {
            $message = DB::transaction(function () use ($conversation, $sender, $data, $attachment) {
                $message = $conversation->messages()->create([
                    'sender_id' => $sender->id,
                    'client_uuid' => $data['client_uuid'],
                    'type' => $attachment ? ($attachment->isImage() ? 'image' : 'file') : 'text',
                    'body' => $data['body'] ?? null,
                    'attachment_id' => $attachment?->id,
                    'reply_to_id' => $data['reply_to_id'] ?? null,
                ]);
                $this->bump($conversation, $message);
                // the sender has obviously read their own message
                $conversation->participants()->where('user_id', $sender->id)->update([
                    'last_read_message_id' => $message->id, 'last_delivered_message_id' => $message->id,
                ]);

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            // a concurrent retry won the race
            return [Message::where('client_uuid', $data['client_uuid'])->firstOrFail(), false];
        }

        return [$message, true];
    }

    private function bump(Conversation $conversation, Message $message): void
    {
        Conversation::whereKey($conversation->id)->update([
            'last_message_id' => $message->id,
            'last_message_at' => $message->created_at,
            'updated_at' => now(),
        ]);
    }

    /**
     * Tick status of messages (MSG-7): 'sent' → 'delivered' (to every recipient) → 'read' (by every recipient).
     * Recipients are the participants who had joined when the message was sent, except the sender.
     *
     * @param  Collection<int, Message>  $messages  all from one conversation
     * @return array<int, array{status:string, delivered:int, read:int, recipients:int}>
     */
    public function statuses(Collection $messages): array
    {
        if ($messages->isEmpty()) {
            return [];
        }
        $conversationId = $messages->first()->conversation_id;
        $participants = ConversationParticipant::where('conversation_id', $conversationId)->get(['user_id', 'joined_at']);
        $counts = MessageReceipt::whereIn('message_id', $messages->pluck('id'))
            ->selectRaw('message_id, COUNT(delivered_at) as delivered, COUNT(read_at) as read_count')
            ->groupBy('message_id')->get()->keyBy('message_id');

        $out = [];
        foreach ($messages as $m) {
            $recipients = $participants->filter(fn ($p) => $p->user_id !== $m->sender_id && $p->joined_at->lessThanOrEqualTo($m->created_at))->count();
            $c = $counts->get($m->id);
            $delivered = (int) ($c->delivered ?? 0);
            $read = (int) ($c->read_count ?? 0);
            $status = $recipients > 0 && $read >= $recipients ? 'read'
                : ($recipients > 0 && $delivered >= $recipients ? 'delivered' : 'sent');
            $out[$m->id] = ['status' => $status, 'delivered' => $delivered, 'read' => $read, 'recipients' => $recipients];
        }

        return $out;
    }

    /** statuses() for messages from several conversations, keyed by message id. */
    private function statusesByConversation(Collection $messages): array
    {
        $out = [];
        foreach ($messages->groupBy('conversation_id') as $group) {
            $out += $this->statuses($group); // `+` keeps the numeric message-id keys
        }

        return $out;
    }

    /**
     * Record delivered/read receipts (PRD 5.3 steps 5–6) and tell senders whose ticks changed.
     *
     * @param  list<int>  $delivered
     * @param  list<int>  $read
     * @return int number of messages updated
     */
    public function receipts(User $user, array $delivered, array $read): int
    {
        $ids = array_values(array_unique(array_merge($delivered, $read)));
        if (! $ids) {
            return 0;
        }
        // only messages from others, in conversations the user is part of
        $messages = Message::whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('sender_id')->orWhere('sender_id', '!=', $user->id))
            ->whereIn('conversation_id', ConversationParticipant::where('user_id', $user->id)->select('conversation_id'))
            ->get(['id', 'conversation_id', 'sender_id', 'created_at']);
        if ($messages->isEmpty()) {
            return 0;
        }
        $readSet = array_flip($read);
        $own = $messages->whereNotNull('sender_id');
        $before = $this->statusesByConversation($own);

        $now = now();
        DB::transaction(function () use ($messages, $user, $readSet, $now) {
            $existing = MessageReceipt::where('user_id', $user->id)->whereIn('message_id', $messages->pluck('id'))->get()->keyBy('message_id');
            $insert = [];
            foreach ($messages as $m) {
                $isRead = isset($readSet[$m->id]);
                $r = $existing->get($m->id);
                if (! $r) {
                    $insert[] = ['message_id' => $m->id, 'user_id' => $user->id, 'delivered_at' => $now, 'read_at' => $isRead ? $now : null, 'created_at' => $now, 'updated_at' => $now];
                } elseif ($isRead && ! $r->read_at) {
                    $r->update(['read_at' => $now, 'delivered_at' => $r->delivered_at ?? $now]);
                }
            }
            if ($insert) {
                MessageReceipt::insertOrIgnore($insert);
            }
            // watermarks drive unread counts
            foreach ($messages->groupBy('conversation_id') as $cid => $ms) {
                $maxAll = $ms->max('id');
                $maxRead = $ms->filter(fn ($m) => isset($readSet[$m->id]))->max('id');
                $p = ConversationParticipant::where('conversation_id', $cid)->where('user_id', $user->id);
                $p->clone()->where('last_delivered_message_id', '<', $maxAll)->update(['last_delivered_message_id' => $maxAll]);
                if ($maxRead) {
                    $p->clone()->where('last_read_message_id', '<', $maxRead)->update(['last_read_message_id' => $maxRead]);
                }
            }
        });

        $after = $this->statusesByConversation($own);
        $changed = $own->filter(fn ($m) => ($before[$m->id]['status'] ?? null) !== $after[$m->id]['status'] || ($before[$m->id]['read'] ?? 0) !== $after[$m->id]['read']);
        foreach ($changed->groupBy('sender_id') as $senderId => $ms) {
            ReceiptsUpdated::dispatch((int) $senderId, $ms->map(fn ($m) => [
                'message_id' => $m->id,
                'conversation_id' => $m->conversation_id,
                ...$after[$m->id],
            ])->values()->all());
        }

        return $messages->count();
    }
}
