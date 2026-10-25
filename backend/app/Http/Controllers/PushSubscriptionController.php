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
            'endpoint' => ['required', 'url', 'max:2000', 'starts_with:https://', function ($attr, $value, $fail) {
                if (! self::allowedEndpoint((string) $value)) {
                    $fail('Unsupported push service.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aesgcm,aes128gcm'],
        ]);
        $request->user()->updatePushSubscription($data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $data['contentEncoding'] ?? 'aes128gcm');

        return response()->json(['message' => 'Subscribed.'], 201);
    }

    /** Only vendor push services (FCM, Mozilla, Windows, Apple); never internal addresses. */
    public static function allowedEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach (config('uniconnect.push.allowed_hosts') as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
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
