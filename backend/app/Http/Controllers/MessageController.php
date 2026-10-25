<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageCreated;
use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * @group Messaging: messages
 */
class MessageController extends Controller
{
    public function __construct(private ChatService $chat) {}

    /**
     * Message history (MSG-11)
     *
     * Cursor pagination by id, newest page first: pass `before` = the smallest id you have.
     * Messages are returned in ascending order. Your own messages carry their tick status.
     *
     * @queryParam before integer Return messages with id < before. Example: 120
     * @queryParam limit integer 1–100, default 50. Example: 50
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);
        $data = $request->validate(['before' => ['nullable', 'integer'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $limit = $data['limit'] ?? 50;

        $messages = $conversation->messages()->withTrashed()
            ->with('sender:id,name', 'attachment', 'replyTo.sender:id,name')
            ->when($data['before'] ?? null, fn ($q, $before) => $q->where('id', '<', $before))
            ->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;
        $messages = $messages->take($limit)->reverse()->values();
        $statuses = $this->chat->statuses($messages->where('sender_id', $request->user()->id));

        return response()->json([
            'data' => MessageResource::list($messages, $statuses),
            'has_more' => $hasMore,
            'next_before' => $hasMore ? $messages->first()?->id : null,
        ]);
    }

    /**
     * Send a message (MSG-5, MSG-6)
     *
     * Idempotent on `client_uuid`: a retry returns the stored message with 200 instead of a duplicate.
     * For files, upload with POST /attachments first and send `attachment_id`.
     * Rate limit: 60 messages per minute.
     */
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);
        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'type' => ['nullable', 'in:text,file,image'],
            'body' => ['nullable', 'string', 'max:10000', 'required_without:attachment_id'],
            'attachment_id' => ['nullable', 'integer'],
            'reply_to_id' => ['nullable', 'integer'],
        ]);
        if (! Gate::allows('post', $conversation)) {
            return response()->json(['message' => 'Only lecturers can post in this announcements-only chat.'], 403);
        }

        [$message, $created] = $this->chat->send($conversation, $request->user(), $data);
        $message->load('sender:id,name', 'attachment', 'replyTo.sender:id,name');
        if ($created) {
            MessageSent::dispatch($message);
            event(new ChatMessageCreated($message));
        }
        $status = $this->chat->statuses(collect([$message]))[$message->id] ?? null;

        return (new MessageResource($message))->withStatus($status)->response()->setStatusCode($created ? 201 : 200);
    }

    /**
     * Delete for everyone (MSG-10)
     *
     * Only your own messages, within one hour. The message stays as "This message was deleted".
     */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        abort_unless($message->sender_id === $request->user()->id, 403, 'You can only delete your own messages.');
        abort_if($message->created_at->lt(now()->subMinutes(Message::DELETE_WINDOW_MINUTES)), 422, 'Messages can only be deleted within one hour.');
        if (! $message->trashed()) {
            // drop the content, keep the row so the conversation shows the placeholder
            $message->forceFill(['body' => null])->save();
            $message->delete();
            MessageDeleted::dispatch($message->conversation_id, $message->id);
        }

        return response()->json(['message' => 'Message deleted.']);
    }

    /**
     * Delivery and read receipts (MSG-7)
     *
     * @bodyParam delivered integer[] Message ids stored on this device. Example: [101, 102]
     * @bodyParam read integer[] Message ids shown on screen. Example: [101]
     */
    public function receipts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delivered' => ['array', 'max:1000'], 'delivered.*' => ['integer'],
            'read' => ['array', 'max:1000'], 'read.*' => ['integer'],
        ]);
        $count = $this->chat->receipts($request->user(), $data['delivered'] ?? [], $data['read'] ?? []);

        return response()->json(['updated' => $count]);
    }

    /**
     * Message info (MSG-7)
     *
     * Who received and who read your message, with times.
     */
    public function info(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('view', $message->conversation);
        abort_unless($message->sender_id === $request->user()->id, 403, 'Message info is available for your own messages.');
        $receipts = $message->receipts()->get()->keyBy('user_id');
        $recipients = $message->conversation->participants()->with('user:id,name')
            ->where('user_id', '!=', $message->sender_id)
            ->where('joined_at', '<=', $message->created_at)->get();
        $status = $this->chat->statuses(collect([$message]))[$message->id];

        return response()->json([
            'message_id' => $message->id,
            ...$status,
            'recipients' => $recipients->map(fn ($p) => [
                'user' => ['id' => $p->user_id, 'name' => $p->user?->name],
                'delivered_at' => $receipts->get($p->user_id)?->delivered_at?->toIso8601ZuluString(),
                'read_at' => $receipts->get($p->user_id)?->read_at?->toIso8601ZuluString(),
            ])->sortBy([['read_at', 'desc'], ['delivered_at', 'desc']])->values(),
        ]);
    }
}
