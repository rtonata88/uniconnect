<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $accountKey = $request->accountThrottleKey();
        $globalKey = $request->globalAccountKey();
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($accountKey, 20)) {
            $seconds = max(RateLimiter::availableIn($key), RateLimiter::tooManyAttempts($accountKey, 20) ? RateLimiter::availableIn($accountKey) : 0);

            return $this->throttled($seconds);
        }
        // account under attack from many networks: allow one attempt per 15 s (slowdown, not lockout).
        // Networks the owner signed in from before are exempt, so an attacker cannot hog the slot.
        $knownKey = 'login-known:'.mb_strtolower((string) $request->input('email'));
        $network = LoginRequest::network((string) $request->ip());
        $known = in_array($network, Cache::get($knownKey, []), true);
        if (! $known && RateLimiter::attempts($globalKey) >= 20) {
            $slowKey = 'login-account-slow:'.mb_strtolower((string) $request->input('email'));
            if (RateLimiter::tooManyAttempts($slowKey, 1)) {
                return $this->throttled(RateLimiter::availableIn($slowKey));
            }
            RateLimiter::hit($slowKey, 15);
        }

        $user = User::where('email', mb_strtolower($request->string('email')))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($accountKey, 3600);
            RateLimiter::hit($globalKey, 3600);
            Audit::log('auth.login_failed', $user, ['email' => $request->input('email'), 'reason' => 'invalid_credentials'], $user?->id);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->is_active) {
            RateLimiter::hit($key, 60);
            Audit::log('auth.login_failed', $user, ['reason' => 'deactivated'], $user->id);

            throw ValidationException::withMessages(['email' => 'This account has been deactivated.']);
        }

        RateLimiter::clear($key);
        RateLimiter::clear($accountKey);
        RateLimiter::clear($globalKey);
        Cache::put($knownKey, array_values(array_unique([...Cache::get($knownKey, []), $network])), now()->addDays(90));
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

    private function throttled(int $seconds): JsonResponse
    {
        return response()->json([
            'message' => "Too many login attempts. Try again in {$seconds} seconds.",
        ], 429)->header('Retry-After', (string) $seconds);
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
