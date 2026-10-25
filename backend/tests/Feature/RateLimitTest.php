<?php

use App\Models\User;

it('SEC-7 limits general API calls to 120 per minute per user', function () {
    $user = User::factory()->create();
    foreach (range(1, 120) as $i) {
        $this->actingAs($user)->getJson('/api/me')->assertOk();
    }
    $this->actingAs($user)->getJson('/api/me')->assertStatus(429)->assertJsonPath('message', 'Too many requests. Please slow down.');
});
