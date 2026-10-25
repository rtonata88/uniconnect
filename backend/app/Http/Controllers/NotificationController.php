<?php

namespace App\Http\Controllers;

use App\Support\Notify\Preferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @group Notifications
 */
class NotificationController extends Controller
{
    public static function present(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            ...$n->data,
            'read_at' => $n->read_at?->toIso8601ZuluString(),
            'created_at' => $n->created_at->toIso8601ZuluString(),
            'updated_at' => $n->updated_at->toIso8601ZuluString('microsecond'),
        ];
    }

    /**
     * Notification centre (NOT-1)
     *
     * Newest first, 30 per page, with the unread count.
     */
    public function index(Request $request): JsonResponse
    {
        $page = $request->user()->notifications()->latest()->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn ($n) => self::present($n)),
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /**
     * Mark as read (NOT-1)
     *
     * @bodyParam ids string[] Notification ids. Example: ["9d6c…"]
     * @bodyParam all boolean Mark everything read. Example: true
     */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['array', 'max:500'], 'ids.*' => ['string'], 'all' => ['boolean']]);
        $query = $request->user()->unreadNotifications();
        if (! ($data['all'] ?? false)) {
            $query->whereIn('id', $data['ids'] ?? []);
        }
        $count = $query->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['updated' => $count, 'unread_count' => $request->user()->unreadNotifications()->count()]);
    }

    /**
     * Notification preferences (NOT-5)
     *
     * Push on/off per category. In-app notifications are always on.
     */
    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['push' => Preferences::forUser($request->user()), 'categories' => Preferences::CATEGORIES]);
    }

    /**
     * Update notification preferences (NOT-5)
     *
     * @bodyParam push object required Example: {"messages": true, "announcements": false}
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $rules = ['push' => ['required', 'array']];
        foreach (array_keys(Preferences::CATEGORIES) as $key) {
            $rules["push.{$key}"] = ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);
        $user = $request->user();
        $current = Preferences::forUser($user);
        $user->notification_preferences = [...($user->notification_preferences ?? []), 'push' => array_merge($current, array_intersect_key($data['push'], $current))];
        $user->save();

        return $this->preferences($request);
    }
}
