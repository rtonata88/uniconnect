<?php

namespace App\Providers;

use App\Support\Registry\ReverbHeartbeat;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // A Reverb instance registers itself from inside its event loop (PRD 5.2).
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if ($event->command === 'reverb:start') {
                $this->app->make(ReverbHeartbeat::class)->start();
            }
        });

        // Password reset links open the web app (AUTH-3).
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email));

        $this->configureRateLimits();
    }

    /**
     * SEC-7. Limits are stored in Redis, so they hold across all API replicas.
     */
    private function configureRateLimits(): void
    {
        $by = fn (Request $r) => $r->user()?->id ? 'u'.$r->user()->id : 'ip'.$r->ip();
        $json = fn (int $max) => fn (Request $r, array $headers) => response()->json(
            ['message' => 'Too many requests. Please slow down.'], 429, $headers);

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($by($r))->response($json(120)));
        RateLimiter::for('messages', fn (Request $r) => Limit::perMinute(60)->by($by($r))->response($json(60)));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(20)->by($by($r))->response($json(20)));
        // unauthenticated endpoints other than login (which has its own failed-attempt limiter)
        RateLimiter::for('guest', fn (Request $r) => Limit::perMinute(10)->by('ip'.$r->ip())->response($json(10)));
    }
}
