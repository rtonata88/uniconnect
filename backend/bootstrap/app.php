<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\AddServedBy::class);
        // Only the gateway may set X-Forwarded-* (client IP drives rate limits and the audit log).
        // Multi-machine: add the gateway host's LAN IP to TRUSTED_PROXIES.
        $middleware->trustProxies(at: array_filter(explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))));
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'activity' => \App\Http\Middleware\TrackActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
