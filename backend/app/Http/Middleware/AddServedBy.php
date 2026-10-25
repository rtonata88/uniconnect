<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds `X-Served-By: <host>:<port>` so the demo can show load balancing (DIST-2).
 */
class AddServedBy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Served-By', self::identity());

        return $response;
    }

    public static function identity(): string
    {
        $host = config('uniconnect.instance.host');
        $port = config('uniconnect.instance.port') ?: 'cli';

        return "{$host}:{$port}";
    }
}
