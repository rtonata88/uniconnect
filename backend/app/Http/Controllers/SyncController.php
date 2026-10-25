<?php

namespace App\Http\Controllers;

use App\Http\Resources\AnnouncementResource;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\CourseResource;
use App\Http\Resources\MaterialResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\SubmissionResource;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\ConversationParticipant;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\Grade;
use App\Models\GradeItem;
use App\Models\Message;
use App\Models\MessageReceipt;
use App\Models\Submission;
use App\Models\User;
use App\Support\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @group Sync
 */
class SyncController extends Controller
{
    /** Rows committed by a transaction that started before our read are caught on the next round. */
    public const OVERLAP_SECONDS = 2;

    public const MESSAGE_PAGE = 500;

    public const FIRST_SYNC_MESSAGES_PER_CONVERSATION = 50;

    public function __construct(private ChatService $chat) {}

    /**
     * Delta sync (PRD 9.3)
     *
     * Everything the user can see that changed after `since`. Start without `since`; then pass
     * the returned `cursor` and loop while `has_more`. Clients upsert by id, so the small
     * overlap between rounds never creates duplicates. `memberships` lists every course and
     * conversation the user still belongs to so clients can drop the rest.
     *
     * @queryParam since string Cursor from the previous response. Example: 2026-10-25T10:15:03Z|981
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['since' => ['nullable', 'string', 'max:64']]);
        [$since, $sinceId, $continuation] = $this->parseCursor($request->query('since'));
        $now = now();

        $courseIds = $user->isAdmin() && ! $user->courses()->exists() ? collect() : $user->courses()->pluck('courses.id');
        $lecturing = $user->courses()->wherePivot('role', 'lecturer')->pluck('courses.id');
        $conversationIds = ConversationParticipant::where('user_id', $user->id)->pluck('conversation_id');

        // messages: keyset pagination by (updated_at, id); the first page of a round overlaps a little
        [$messages, $hasMore] = $since === null
            ? [$this->latestMessages($conversationIds), false]
            : $this->changedMessages($conversationIds, $continuation ? $since : $since->copy()->subSeconds(self::OVERLAP_SECONDS), $continuation ? $sinceId : 0);
        $mine = $messages->where('sender_id', $user->id);
        $statuses = [];
        foreach ($mine->groupBy('conversation_id') as $group) {
            $statuses += $this->chat->statuses($group);
        }

        $payload = [
            'messages' => MessageResource::list($messages, $statuses),
            'deleted' => ['messages' => $messages->whereNotNull('deleted_at')->pluck('id')->values()],
        ];

        if (! $continuation) {
            $from = $since?->copy()->subSeconds(self::OVERLAP_SECONDS);
            $payload += $this->lms($user, $courseIds, $lecturing, $from);
            $payload['deleted'] += $payload['_deleted'];
            unset($payload['_deleted']);

            $conversations = ConversationController::query($user)
                ->when($from, fn ($q) => $q->where('conversations.updated_at', '>=', $from))
                ->get();
            $payload['conversations'] = ConversationResource::collection($conversations)->resolve($request);
            $payload['receipts'] = $this->receipts($user, $from);
            $payload['notifications'] = $user->notifications()
                ->when($from, fn ($q) => $q->where('updated_at', '>=', $from))
                ->latest()->limit(200)->get()->map(fn ($n) => NotificationController::present($n));
            $payload['memberships'] = ['course_ids' => $courseIds->values(), 'conversation_ids' => $conversationIds->values()];
        }

        $last = $messages->last();
        $cursor = $hasMore
            ? $last->updated_at->toIso8601ZuluString().'|'.$last->id.'|more'
            : $now->toIso8601ZuluString().'|0';

        return response()->json(['cursor' => $cursor, ...$payload, 'has_more' => $hasMore]);
    }

    /** @return array{0: ?Carbon, 1: int, 2: bool} */
    private function parseCursor(?string $cursor): array
    {
        if (! $cursor) {
            return [null, 0, false];
        }
        $parts = explode('|', $cursor);
        try {
            $ts = Carbon::parse($parts[0]);
        } catch (\Throwable) {
            return [null, 0, false];
        }

        return [$ts, (int) ($parts[1] ?? 0), ($parts[2] ?? '') === 'more'];
    }

    private function messageQuery(Collection $conversationIds)
    {
        return Message::withTrashed()->whereIn('conversation_id', $conversationIds)
            ->with('sender:id,name', 'attachment', 'replyTo.sender:id,name');
    }

    /** First sync: the newest messages of every conversation (older history pages in on demand). */
    private function latestMessages(Collection $conversationIds): Collection
    {
        return $conversationIds->flatMap(fn ($cid) => $this->messageQuery(collect([$cid]))
            ->orderByDesc('id')->limit(self::FIRST_SYNC_MESSAGES_PER_CONVERSATION)->get())
            ->sortBy('id')->values();
    }

    /** @return array{0: Collection, 1: bool} */
    private function changedMessages(Collection $conversationIds, Carbon $ts, int $id): array
    {
        $rows = $this->messageQuery($conversationIds)
            ->where(fn ($q) => $q->where('updated_at', '>', $ts)->orWhere(fn ($q) => $q->where('updated_at', '=', $ts)->where('id', '>', $id)))
            ->orderBy('updated_at')->orderBy('id')
            ->limit(self::MESSAGE_PAGE + 1)->get();

        return [$rows->take(self::MESSAGE_PAGE)->values(), $rows->count() > self::MESSAGE_PAGE];
    }

    /** Courses, materials, announcements, assignments, my submissions and released grades. */
    private function lms(User $user, Collection $courseIds, Collection $lecturing, ?Carbon $from): array
    {
        $changed = fn ($q) => $from ? $q->where(fn ($q) => $q->where('updated_at', '>=', $from)->orWhere('deleted_at', '>=', $from)) : $q;
        $deleted = [];

        $courses = Course::withTrashed()->whereIn('id', $courseIds)->with('lecturers:id,name', 'conversation')->withCount('students')
            ->when($from, fn ($q) => $q->where(fn ($q) => $q->where('updated_at', '>=', $from)->orWhere('deleted_at', '>=', $from)))
            ->get();
        $deleted['courses'] = $courses->whereNotNull('deleted_at')->pluck('id')->values();
        $courses = $courses->whereNull('deleted_at')->map(function ($c) use ($lecturing) {
            $c->setRelation('pivot', (object) ['role' => $lecturing->contains($c->id) ? 'lecturer' : 'student']);

            return $c;
        });

        // students only keep published items; drafts and deleted rows are removed from their device
        $visible = fn ($row) => $row->deleted_at === null && ($lecturing->contains($row->course_id) || $row->isPublished());
        $content = function (string $model) use ($courseIds, $changed, $visible, &$deleted) {
            $rows = $changed($model::withTrashed()->whereIn('course_id', $courseIds))->with('attachment')->get();
            $key = (new $model)->getTable() === 'course_materials' ? 'materials' : (new $model)->getTable();
            $deleted[$key] = $rows->reject($visible)->pluck('id')->values();

            return $rows->filter($visible)->values();
        };
        $materials = $content(CourseMaterial::class);
        $assignments = $content(Assignment::class);
        $announcements = $changed(Announcement::withTrashed()->whereIn('course_id', $courseIds))->with('author:id,name')->get();
        $deleted['announcements'] = $announcements->reject($visible)->pluck('id')->values();
        $announcements = $announcements->filter($visible)->values();

        $submissions = Submission::withTrashed()->where('student_id', $user->id)
            ->when($from, fn ($q) => $q->where(fn ($q) => $q->where('updated_at', '>=', $from)->orWhere('deleted_at', '>=', $from)))
            ->with('attachment', 'assignment.gradeItem')->get();
        $deleted['my_submissions'] = $submissions->whereNotNull('deleted_at')->pluck('id')->values();

        // released grades of this student (LMS-10); un-released items are removed again
        $items = GradeItem::whereIn('course_id', $courseIds)->whereNotIn('course_id', $lecturing)
            ->when($from, fn ($q) => $q->where(fn ($q) => $q->where('updated_at', '>=', $from)->orWhereHas('grades', fn ($g) => $g->where('student_id', $user->id)->where('updated_at', '>=', $from))))
            ->get();
        $grades = Grade::where('student_id', $user->id)->whereIn('grade_item_id', $items->pluck('id'))->get()->keyBy('grade_item_id');
        $deleted['grades'] = $items->whereNull('released_at')->pluck('id')->values();

        return [
            'courses' => CourseResource::collection($courses->values())->resolve(),
            'materials' => MaterialResource::collection($materials)->resolve(),
            'announcements' => AnnouncementResource::collection($announcements)->resolve(),
            'assignments' => AssignmentResource::collection($assignments)->resolve(),
            'my_submissions' => SubmissionResource::collection($submissions->whereNull('deleted_at')->values())->resolve(),
            // one row per released grade item; id = grade item id
            'grades' => $items->whereNotNull('released_at')->map(fn ($item) => [
                'id' => $item->id,
                'grade_item_id' => $item->id,
                'course_id' => $item->course_id,
                'title' => $item->title,
                'source_type' => $item->source_type,
                'source_id' => $item->source_id,
                'max_score' => $item->max_score,
                'weight_percent' => $item->weight_percent,
                'released_at' => $item->released_at->toIso8601ZuluString(),
                'score' => $grades->get($item->id)?->score,
                'comment' => $grades->get($item->id)?->comment,
            ])->values(),
            '_deleted' => $deleted,
        ];
    }

    /** Tick changes for my messages since the last round. */
    private function receipts(User $user, ?Carbon $from): array
    {
        if (! $from) {
            return []; // first sync: messages already carry their status
        }
        $ids = MessageReceipt::where('updated_at', '>=', $from)
            ->whereIn('message_id', Message::where('sender_id', $user->id)->select('id'))
            ->distinct()->pluck('message_id');
        $messages = Message::whereIn('id', $ids)->get();
        $out = [];
        foreach ($messages->groupBy('conversation_id') as $group) {
            foreach ($this->chat->statuses($group) as $id => $s) {
                $out[] = ['message_id' => $id, 'status' => $s['status'], 'read' => $s['read'], 'recipients' => $s['recipients']];
            }
        }

        return $out;
    }
}
