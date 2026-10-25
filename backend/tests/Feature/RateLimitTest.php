<?php

use App\Models\User;

it('SEC-7 limits general API calls to 120 per minute per user', function () {
    $user = User::factory()->create();
    foreach (range(1, 120) as $i) {
        $this->actingAs($user)->getJson('/api/me')->assertOk();
    }
    $this->actingAs($user)->getJson('/api/me')->assertStatus(429)->assertJsonPath('message', 'Too many requests. Please slow down.');
});

it('only server-issued load-test tokens skip the per-user limits', function () {
    $user = User::factory()->create();
    $normal = $user->createToken('web', ['*'])->plainTextToken;
    foreach (range(1, 120) as $i) {
        $this->withToken($normal)->getJson('/api/me')->assertOk();
        app('auth')->forgetGuards();
    }
    $this->withToken($normal)->getJson('/api/me')->assertStatus(429);
    app('auth')->forgetGuards();

    $loadtest = User::factory()->create()->createToken('loadtest', ['*', 'loadtest'])->plainTextToken;
    foreach (range(1, 130) as $i) {
        $this->withToken($loadtest)->getJson('/api/me')->assertOk();
        app('auth')->forgetGuards();
    }
});
