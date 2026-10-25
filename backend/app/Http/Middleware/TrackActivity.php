<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects deactivated accounts and keeps `last_seen_at` fresh (at most one write
 * per user per minute, coordinated across API replicas through the Redis cache).
 */
class TrackActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => 'This account has been deactivated.'], 401);
        }

        if ($user && Cache::add("last-seen:{$user->id}", 1, 60)) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
