<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('AUTH-4 returns the current user with role', function () {
    $user = User::factory()->lecturer()->create();
    $this->actingAs($user)->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.role', 'lecturer')
        ->assertJsonPath('data.email', $user->email);
});

it('AUTH-5 updates the name', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->patchJson('/api/me', ['name' => 'New Name'])->assertOk()->assertJsonPath('data.name', 'New Name');
});

it('AUTH-5 changes the password only with the current password', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $other = $user->createToken('other');

    $this->withToken($token)->patchJson('/api/me', [
        'current_password' => 'wrong', 'password' => 'Another1pass', 'password_confirmation' => 'Another1pass',
    ])->assertStatus(422)->assertJsonValidationErrors('current_password');

    $this->withToken($token)->patchJson('/api/me', [
        'current_password' => 'Password123!', 'password' => 'Another1pass', 'password_confirmation' => 'Another1pass',
    ])->assertOk();

    // other devices are signed out, this one stays
    expect($user->tokens()->pluck('id')->all())->not->toContain($other->accessToken->id)->toHaveCount(1);
});

it('AUTH-5 uploads an avatar to private storage under a random name', function () {
    Storage::fake('s3', ['serve' => true]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 200, 200)])
        ->assertOk()
        ->assertJsonPath('data.avatar_url', fn ($url) => is_string($url) && $url !== '');

    $path = $user->fresh()->avatar_path;
    expect($path)->toStartWith('avatars/')->not->toContain('me.png');
    Storage::disk('s3')->assertExists($path);
});

it('AUTH-5 rejects non-image avatars', function () {
    Storage::fake('s3');
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/me/avatar', ['avatar' => UploadedFile::fake()->create('x.php', 10, 'application/x-php')])
        ->assertStatus(422);
});
