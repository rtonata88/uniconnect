<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * @group Authentication
 */
class AuthController extends Controller
{
    /**
     * Log in (AUTH-1)
     *
     * Returns a Sanctum bearer token (expires after 7 days). Five failed attempts per
     * minute for the same email + IP lock the pair for the rest of the minute.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $key = $request->throttleKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "Too many login attempts. Try again in {$seconds} seconds.",
            ], 429)->header('Retry-After', (string) $seconds);
        }

        $user = User::where('email', mb_strtolower($request->string('email')))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            RateLimiter::hit($key, 60);
            Audit::log('auth.login_failed', $user, ['email' => $request->input('email'), 'reason' => 'invalid_credentials'], $user?->id);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->is_active) {
            RateLimiter::hit($key, 60);
            Audit::log('auth.login_failed', $user, ['reason' => 'deactivated'], $user->id);

            throw ValidationException::withMessages(['email' => 'This account has been deactivated.']);
        }

        RateLimiter::clear($key);
        $device = $request->input('device_name') ?: substr((string) $request->userAgent(), 0, 100) ?: 'web';
        $token = $user->createToken($device, ['*'], now()->addMinutes((int) config('sanctum.expiration')));
        $user->forceFill(['last_seen_at' => now()])->save();
        Audit::log('auth.login', $user, ['device' => $device], $user->id);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601ZuluString(),
            'user' => new MeResource($user),
        ]);
    }

    /**
     * Log out (AUTH-2)
     *
     * Revokes the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Log out all devices (AUTH-2)
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $count = $request->user()->tokens()->delete();
        Audit::log('auth.logout_all', $request->user(), ['tokens' => $count]);

        return response()->json(['message' => 'Logged out of all devices.']);
    }
}
