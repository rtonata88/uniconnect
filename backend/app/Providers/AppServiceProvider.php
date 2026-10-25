<?php

namespace App\Providers;

use App\Support\Registry\ReverbHeartbeat;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
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
    }
}
