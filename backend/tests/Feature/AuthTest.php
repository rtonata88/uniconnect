<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(fn () => RateLimiter::clear('login:anna@uniconnect.test|127.0.0.1'));

it('AUTH-1 logs in with email and password and returns a 7 day token', function () {
    $user = User::factory()->student()->create(['email' => 'anna@uniconnect.test']);

    $res = $this->postJson('/api/auth/login', ['email' => 'Anna@UniConnect.test', 'password' => 'Password123!'])
        ->assertOk()
        ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email', 'role']])
        ->assertJsonPath('user.role', 'student');

    expect(now()->diffInDays($res->json('expires_at')))->toBeGreaterThan(6.9);
    $this->withToken($res->json('token'))->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
    expect(AuditLog::where('action', 'auth.login')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('AUTH-1 rejects wrong credentials with the standard error shape and audits it', function () {
    User::factory()->create(['email' => 'anna@uniconnect.test']);

    $this->postJson('/api/auth/login', ['email' => 'anna@uniconnect.test', 'password' => 'wrong'])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['email']]);

    expect(AuditLog::where('action', 'auth.login_failed')->count())->toBe(1);
});

it('AUTH-1 locks login after 5 failed attempts per minute for email + IP', function () {
    User::factory()->create(['email' => 'anna@uniconnect.test']);
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/auth/login', ['email' => 'anna@uniconnect.test', 'password' => 'wrong'])->assertStatus(422);
    }

    $this->postJson('/api/auth/login', ['email' => 'anna@uniconnect.test', 'password' => 'Password123!'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    // a different IP is not affected
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->postJson('/api/auth/login', ['email' => 'anna@uniconnect.test', 'password' => 'Password123!'])
        ->assertOk();
});

it('AUTH-2 logout revokes only the current token', function () {
    $user = User::factory()->create();
    $a = $user->createToken('a')->plainTextToken;
    $b = $user->createToken('b')->plainTextToken;

    $this->withToken($a)->postJson('/api/auth/logout')->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($a)->getJson('/api/me')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($b)->getJson('/api/me')->assertOk();
});

it('AUTH-2 logout-all revokes every token', function () {
    $user = User::factory()->create();
    $a = $user->createToken('a')->plainTextToken;
    $user->createToken('b');

    $this->withToken($a)->postJson('/api/auth/logout-all')->assertOk();
    expect($user->tokens()->count())->toBe(0);
});

it('AUTH-3 sends a reset link to the web app and resets the password', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'anna@uniconnect.test']);
    $user->createToken('old');

    $this->postJson('/api/auth/forgot-password', ['email' => 'anna@uniconnect.test'])->assertOk();
    // same answer for unknown addresses (no account enumeration)
    $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@uniconnect.test'])->assertOk();

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token, $user) {
        $token = $n->token;

        return str_contains($n->toMail($user)->actionUrl, '/reset-password?token=');
    });

    $this->postJson('/api/auth/reset-password', [
        'token' => $token, 'email' => 'anna@uniconnect.test',
        'password' => 'NewPassw0rd', 'password_confirmation' => 'NewPassw0rd',
    ])->assertOk();

    expect($user->tokens()->count())->toBe(0);
    $this->postJson('/api/auth/login', ['email' => 'anna@uniconnect.test', 'password' => 'NewPassw0rd'])->assertOk();
});

it('SEC-1 requires a token on protected routes', function () {
    $this->getJson('/api/me')->assertUnauthorized()->assertJsonStructure(['message']);
    $this->getJson('/api/admin/users')->assertUnauthorized();
});

it('AUTH-6 deactivated users cannot log in', function () {
    User::factory()->inactive()->create(['email' => 'anna@uniconnect.test']);
    $this->postJson('/api/auth/login', ['email' => 'anna@uniconnect.test', 'password' => 'Password123!'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'This account has been deactivated.');
});

it('AUTH-1 limits failed attempts per account across IPs of one network without locking out the owner elsewhere', function () {
    User::factory()->create(['email' => 'anna@uniconnect.test']);
    foreach (range(1, 20) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.{$i}"])
            ->postJson('/api/auth/login', ['email' => 'anna@uniconnect.test', 'password' => 'wrong'])->assertStatus(422);
    }
    // rotating addresses inside the attacker's network is blocked
    $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.200'])
        ->postJson('/api/auth/login', ['email' => 'anna@uniconnect.test', 'password' => 'Password123!'])
        ->assertStatus(429);
    // the owner on another network can still sign in
    $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])
        ->postJson('/api/auth/login', ['email' => 'anna@uniconnect.test', 'password' => 'Password123!'])
        ->assertOk();
});

it('AUTH-1 limits login attempts per IP across all emails', function () {
    foreach (range(1, 30) as $i) {
        $this->postJson('/api/auth/login', ['email' => "u{$i}@uniconnect.test", 'password' => 'x'])->assertStatus(422);
    }
    $this->postJson('/api/auth/login', ['email' => 'other@uniconnect.test', 'password' => 'x'])->assertStatus(429);
});

it('ignores spoofed X-Forwarded-For from untrusted clients', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->withHeader('X-Forwarded-For', '1.2.3.4')
        ->getJson('/api/health');
    expect(request()->ip())->toBe('203.0.113.5');
});
