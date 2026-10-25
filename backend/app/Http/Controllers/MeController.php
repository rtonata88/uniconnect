<?php

namespace App\Http\Controllers;

use App\Http\Resources\MeResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * @group Profile
 */
class MeController extends Controller
{
    /**
     * Current user (AUTH-4)
     *
     * The signed-in user with role and enrolled courses.
     */
    public function show(Request $request): MeResource
    {
        return new MeResource($request->user());
    }

    /**
     * Update profile (AUTH-5)
     *
     * Change name and/or password. Changing the password needs the current password
     * and revokes the user's other tokens.
     */
    public function update(Request $request): MeResource
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'current_password' => ['required_with:password', 'current_password:sanctum'],
            'password' => ['sometimes', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }
        if (isset($data['password'])) {
            $user->password = $data['password'];
            $current = $user->currentAccessToken()?->id;
            $user->tokens()->when($current, fn ($q) => $q->where('id', '!=', $current))->delete();
        }
        $user->save();

        return new MeResource($user);
    }

    /**
     * Upload avatar (AUTH-5)
     *
     * JPEG, PNG or WebP up to 2 MB. Stored in the private bucket under a random name.
     */
    public function avatar(Request $request): MeResource
    {
        $request->validate([
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'],
        ]);
        $user = $request->user();
        $file = $request->file('avatar');
        $path = 'avatars/'.Str::random(40).'.'.$file->guessExtension();
        Storage::put($path, $file->getContent(), ['ContentType' => $file->getMimeType()]);

        if ($user->avatar_path) {
            Storage::delete($user->avatar_path);
        }
        $user->forceFill(['avatar_path' => $path])->save();

        return new MeResource($user);
    }
}
