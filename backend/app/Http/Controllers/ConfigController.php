<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * @group System
 */
class ConfigController extends Controller
{
    /**
     * Client configuration
     *
     * Public values the web app needs at runtime: Reverb app key and the VAPID public key.
     * Read at runtime so the same build works on any host.
     *
     * @unauthenticated
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'reverb' => ['key' => config('reverb.apps.apps.0.key')],
            'vapid_public_key' => config('webpush.vapid.public_key'),
            'upload_max_bytes' => config('uniconnect.uploads.max_kb') * 1024,
        ]);
    }
}
