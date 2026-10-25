<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * @group Authentication
 */
class PasswordResetController extends Controller
{
    /**
     * Request a password reset link (AUTH-3)
     *
     * Always answers with the same message so it cannot be used to discover accounts.
     * In development the link is written to the log (MAIL_MAILER=log).
     *
     * @unauthenticated
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);
        Password::sendResetLink(['email' => mb_strtolower($request->string('email'))]);

        return response()->json(['message' => 'If that address has an account, a reset link has been sent.']);
    }

    /**
     * Reset the password with the emailed token (AUTH-3)
     *
     * Revokes every existing token for the account.
     *
     * @unauthenticated
     */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => __($status)]);
    }
}
