<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Notifications
 */
class PushSubscriptionController extends Controller
{
    /**
     * Subscribe this browser / installed PWA to Web Push (NOT-3)
     *
     * Body is the browser's PushSubscription JSON.
     *
     * @bodyParam endpoint string required Example: https://fcm.googleapis.com/fcm/send/abc
     * @bodyParam keys.p256dh string required
     * @bodyParam keys.auth string required
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aesgcm,aes128gcm'],
        ]);
        $request->user()->updatePushSubscription($data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $data['contentEncoding'] ?? 'aes128gcm');

        return response()->json(['message' => 'Subscribed.'], 201);
    }

    /**
     * Unsubscribe
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);
        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->json(['message' => 'Unsubscribed.']);
    }
}
