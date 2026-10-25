<?php

use App\Http\Middleware\AddServedBy;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackActivity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // POST /api/broadcasting/auth authorises private/presence channels with the bearer token (SEC-3)
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum', 'activity']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AddServedBy::class);
        $middleware->append(SecurityHeaders::class);
        // Only the gateway may set X-Forwarded-* (client IP drives rate limits and the audit log).
        // Multi-machine: add the gateway host's LAN IP to TRUSTED_PROXIES.
        $middleware->trustProxies(at: array_filter(explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))));
        // token API: never redirect guests to a login page, always answer 401 JSON
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->alias([
            'role' => EnsureRole::class,
            'activity' => TrackActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // don't reveal model class names ("No query results for model [App\Models\…]")
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getPrevious() instanceof ModelNotFoundException ? 'Not found.' : ($e->getMessage() ?: 'Not found.')], 404);
            }
        });
    })->create();
