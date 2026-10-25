<?php

namespace App\Http\Controllers;

use App\Events\AddedToGroup;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Chat\ChatService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * @group Messaging: conversations
 */
class ConversationController extends Controller
{
    public function __construct(private ChatService $chat) {}

    /** Conversations of $user with everything the list needs (MSG-1). */
    public static function query(User $user): Builder
    {
        return Conversation::query()
            ->select('conversations.*')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->with(['participants.user:id,name,avatar_path,last_seen_at', 'lastMessage.sender:id,name', 'lastMessage.attachment'])
            ->addSelect(['unread_count' => Message::selectRaw('COUNT(*)')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->where(fn ($q) => $q->whereNull('messages.sender_id')->orWhere('messages.sender_id', '!=', $user->id))
                ->whereNull('messages.deleted_at')
                ->where('messages.type', '!=', 'system')
                ->whereRaw('messages.id > (SELECT last_read_message_id FROM conversation_participants cp WHERE cp.conversation_id = conversations.id AND cp.user_id = ?)', [$user->id]),
            ]);
    }

    /**
     * Chat list (MSG-1)
     *
     * Every conversation with the last message preview, unread count and the other user for direct chats.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $conversations = self::query($request->user())
            ->orderByDesc(DB::raw('COALESCE(last_message_at, conversations.created_at)'))
            ->get();

        return ConversationResource::collection($conversations);
    }

    /**
     * Start a chat (MSG-2, MSG-3)
     *
     * Direct: `{type: "direct", user_id}` returns the existing chat with that user if there is one.
     * Group: `{type: "group", title, user_ids}`. Members must share a course with you (admins: anyone).
     */
    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate([
            'type' => ['required', 'in:direct,group'],
            'user_id' => ['required_if:type,direct', 'integer', 'exists:users,id'],
            'title' => ['required_if:type,group', 'nullable', 'string', 'max:100'],
            'user_ids' => ['required_if:type,group', 'array', 'max:256'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        if ($data['type'] === 'direct') {
            $other = User::findOrFail($data['user_id']);
            abort_if($other->id === $me->id, 422, 'You cannot start a chat with yourself.');
            $this->assertCanChat($me, $other);
            $key = Conversation::directKey($me->id, $other->id);
            if ($existing = Conversation::where('direct_key', $key)->first()) {
                return $this->present($existing, $me)->response()->setStatusCode(200);
            }
            try {
                $conversation = DB::transaction(function () use ($me, $other, $key) {
                    $c = Conversation::create(['type' => 'direct', 'direct_key' => $key, 'created_by' => $me->id]);
                    $this->chat->addParticipants($c, [$me, $other], 'member', announce: false);

                    return $c;
                });
            } catch (UniqueConstraintViolationException) {
                return $this->present(Conversation::where('direct_key', $key)->firstOrFail(), $me)->response()->setStatusCode(200);
            }

            return $this->present($conversation, $me)->response()->setStatusCode(201);
        }

        $members = User::whereIn('id', array_diff($data['user_ids'], [$me->id]))->get();
        foreach ($members as $member) {
            $this->assertCanChat($me, $member);
        }
        $conversation = DB::transaction(function () use ($me, $members, $data) {
            $c = Conversation::create(['type' => 'group', 'title' => $data['title'], 'created_by' => $me->id]);
            $this->chat->addParticipants($c, [$me], 'admin', announce: false);
            $this->chat->addParticipants($c, $members, 'member', $me, announce: false);
            $this->chat->systemMessage($c, "{$me->name} created the group \"{$data['title']}\"");

            return $c;
        });
        foreach ($members as $member) {
            event(new AddedToGroup($conversation, $member, $me));
        }

        return $this->present($conversation, $me)->response()->setStatusCode(201);
    }

    /**
     * Conversation details with participants
     */
    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        Gate::authorize('view', $conversation);

        return $this->present($conversation, $request->user());
    }

    /**
     * Update a conversation
     *
     * Group admins rename; lecturers switch a course chat to announcements only (MSG-4).
     */
    public function update(Request $request, Conversation $conversation): ConversationResource
    {
        Gate::authorize('manage', $conversation);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:100'],
            'announcements_only' => ['sometimes', 'boolean'],
        ]);
        if ($conversation->type === 'course') {
            unset($data['title']); // follows the course
        } else {
            unset($data['announcements_only']);
        }
        $conversation->fill($data)->save();
        if ($conversation->wasChanged('announcements_only')) {
            $this->chat->systemMessage($conversation, $conversation->announcements_only
                ? "{$request->user()->name} set this chat to announcements only"
                : "{$request->user()->name} opened this chat to everyone");
        }
        $this->chat->notifyMembers($conversation, 'updated');

        return $this->present($conversation, $request->user());
    }

    /**
     * Add members to a group (MSG-3, group admins)
     */
    public function addParticipants(Request $request, Conversation $conversation): ConversationResource
    {
        Gate::authorize('manage', $conversation);
        abort_unless($conversation->type === 'group', 422, 'Course chat members follow the course enrolment.');
        $data = $request->validate(['user_ids' => ['required', 'array', 'min:1', 'max:256'], 'user_ids.*' => ['integer', 'exists:users,id']]);
        $me = $request->user();
        $users = User::whereIn('id', $data['user_ids'])->get();
        foreach ($users as $u) {
            $this->assertCanChat($me, $u);
        }
        $added = $this->chat->addParticipants($conversation, $users, 'member', $me);
        foreach ($added as $u) {
            event(new AddedToGroup($conversation, $u, $me));
        }

        return $this->present($conversation->refresh(), $me);
    }

    /**
     * Remove a member (group admins)
     */
    public function removeParticipant(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        Gate::authorize('manage', $conversation);
        abort_unless($conversation->type === 'group', 422, 'Course chat members follow the course enrolment.');
        abort_unless($this->chat->removeParticipant($conversation, $user, $request->user()), 404, 'Not a member.');

        return response()->json(['message' => "{$user->name} removed."]);
    }

    /**
     * Leave a group (MSG-3)
     */
    public function leave(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);
        abort_unless($conversation->type === 'group', 422, 'You can only leave group chats.');
        $this->chat->removeParticipant($conversation, $request->user());

        return response()->json(['message' => 'You left the group.']);
    }

    /**
     * Mute or unmute (MSG-12)
     *
     * Muted chats still count unread messages but send no push notifications.
     *
     * @bodyParam hours integer Mute for this many hours; 0 unmutes; omit for "always" (1 year). Example: 8
     */
    public function mute(Request $request, Conversation $conversation): ConversationResource
    {
        Gate::authorize('view', $conversation);
        $data = $request->validate(['hours' => ['nullable', 'integer', 'min:0', 'max:8760']]);
        $hours = $data['hours'] ?? 8760;
        $conversation->participants()->where('user_id', $request->user()->id)
            ->update(['muted_until' => $hours > 0 ? now()->addHours($hours) : null, 'updated_at' => now()]);
        $conversation->touch();

        return $this->present($conversation, $request->user());
    }

    private function present(Conversation $conversation, User $user): ConversationResource
    {
        $fresh = self::query($user)->whereKey($conversation->id)->firstOrFail();

        return (new ConversationResource($fresh))->withParticipants();
    }

    /** MSG-2: users who share a course can chat; admins can chat with anyone. */
    private function assertCanChat(User $me, User $other): void
    {
        if ($me->isAdmin() || $other->isAdmin() || $me->sharesCourseWith($other)) {
            return;
        }
        throw ValidationException::withMessages(['user_id' => "You can only chat with people who share a course with you ({$other->name})."]);
    }
}
