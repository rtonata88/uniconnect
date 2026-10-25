<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-6: security headers on every API response, also when an instance is reached
 * directly (multi-machine) rather than through Traefik, which adds HSTS and the web CSP.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        if ($request->is('api/*')) {
            // JSON never needs to load anything
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
            if (! $headers->has('Cache-Control') || $headers->get('Cache-Control') === 'no-cache, private') {
                $headers->set('Cache-Control', 'no-store, private');
            }
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
