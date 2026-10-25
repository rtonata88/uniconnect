<?php

use Illuminate\Support\Facades\Route;

// The web app is the React PWA (frontend/), served by the `web` process.
Route::get('/', fn () => response()->json(['name' => config('app.name'), 'api' => url('/api')]));
