<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AddServedBy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    /**
     * Health check (no auth). Used by Traefik, the heartbeat sidecar and the client's
     * connectivity probe. Returns 503 when the database or Redis is unreachable.
     *
     * @unauthenticated
     */
    public function __invoke(): JsonResponse
    {
        $db = $this->check(fn () => DB::connection()->getPdo()->query('SELECT 1')->fetchColumn() == 1);
        $redis = $this->check(fn () => (bool) Redis::connection()->command('ping'));
        $ok = $db && $redis;

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'hostname' => config('uniconnect.instance.hostname'),
            'instance' => AddServedBy::identity(),
            'db' => $db,
            'redis' => $redis,
            'time' => now()->toIso8601ZuluString('millisecond'),
        ], $ok ? 200 : 503);
    }

    private function check(callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable) {
            return false;
        }
    }
}
