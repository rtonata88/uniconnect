<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // The dashboard shows job payloads. It has no login of its own (the app uses API tokens),
        // so it is only served to the machine running the stack (through the gateway the client
        // IP is the real one: TRUSTED_PROXIES). Set HORIZON_ALLOWED_IPS to open it to others.
        Horizon::auth(fn ($request) => in_array($request->ip(), config('uniconnect.horizon_allowed_ips'), true));
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null) => false); // access is decided by Horizon::auth above
    }
}
