<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Message;
use App\Models\User;
use App\Support\System\SystemStatus;
use Illuminate\Http\JsonResponse;

/**
 * @group Admin: system
 */
class SystemController extends Controller
{
    /**
     * System status (DIST-6)
     *
     * Every registered instance (api, reverb, worker, scheduler) with heartbeat age and uptime,
     * what the gateway routes to, MySQL primary + replica (replication lag), Redis, MinIO,
     * queue sizes and live WebSocket connections.
     */
    public function __invoke(SystemStatus $status): JsonResponse
    {
        return response()->json([
            ...$status->snapshot(),
            'counts' => [
                'users' => User::count(),
                'active_users' => User::where('is_active', true)->count(),
                'courses' => Course::count(),
                'messages' => Message::count(),
            ],
        ]);
    }
}
