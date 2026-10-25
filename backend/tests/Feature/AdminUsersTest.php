<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('AUTH-6 only admins can manage users', function () {
    $student = User::factory()->student()->create();
    $this->actingAs($student)->getJson('/api/admin/users')->assertForbidden();
    $this->actingAs($student)->postJson('/api/admin/users', [])->assertForbidden();
});

it('AUTH-6 admin lists and searches users', function () {
    User::factory()->student()->create(['name' => 'Zed Unique']);
    $this->actingAs($this->admin)->getJson('/api/admin/users?search=Unique')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Zed Unique')
        ->assertJsonStructure(['data', 'meta' => ['current_page', 'total'], 'links']);
});

it('AUTH-6 admin creates a user', function () {
    $this->actingAs($this->admin)->postJson('/api/admin/users', [
        'name' => 'New Lecturer', 'email' => 'NEW@uniconnect.test', 'role' => 'lecturer', 'password' => 'Password123!',
    ])->assertCreated()->assertJsonPath('data.email', 'new@uniconnect.test');

    expect(AuditLog::where('action', 'user.created')->count())->toBe(1);
});

it('AUTH-6 validates new users', function () {
    $this->actingAs($this->admin)->postJson('/api/admin/users', ['email' => 'bad', 'role' => 'wizard'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'role', 'password']);
});

it('AUTH-6 imports users from CSV and reports bad rows', function () {
    User::factory()->create(['email' => 'taken@uniconnect.test']);
    $csv = "name,email,role,student_number\n"
        ."Ana One,ana1@uniconnect.test,student,S1\n"
        ."Bob Two,bob2@uniconnect.test,lecturer,\n"
        ."Dup,taken@uniconnect.test,student,\n"
        ."Bad Role,bad@uniconnect.test,wizard,\n";

    $res = $this->actingAs($this->admin)->post('/api/admin/users/import', [
        'file' => UploadedFile::fake()->createWithContent('users.csv', $csv),
    ], ['Accept' => 'application/json'])->assertCreated();

    expect($res->json('created'))->toHaveCount(2)
        ->and($res->json('created.0.password'))->toBeString()
        ->and($res->json('errors'))->toHaveCount(2)
        ->and(User::where('email', 'bob2@uniconnect.test')->value('role')->value)->toBe('lecturer');
});

it('AUTH-6 deactivating a user revokes tokens and is audited', function () {
    $user = User::factory()->student()->create();
    $token = $user->createToken('phone')->plainTextToken;

    $this->actingAs($this->admin)->patchJson("/api/admin/users/{$user->id}", ['is_active' => false])->assertOk();

    expect($user->tokens()->count())->toBe(0)
        ->and(AuditLog::where('action', 'user.deactivated')->where('subject_id', $user->id)->exists())->toBeTrue();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
});

it('SEC-9 role changes are audited with old and new role', function () {
    $user = User::factory()->student()->create();
    $this->actingAs($this->admin)->patchJson("/api/admin/users/{$user->id}", ['role' => 'lecturer'])->assertOk();

    $log = AuditLog::where('action', 'user.role_changed')->first();
    expect($log->meta)->toBe(['from' => 'student', 'to' => 'lecturer']);
});

it('admins cannot deactivate or demote themselves', function () {
    $this->actingAs($this->admin)->patchJson("/api/admin/users/{$this->admin->id}", ['is_active' => false])->assertForbidden();
    $this->actingAs($this->admin)->patchJson("/api/admin/users/{$this->admin->id}", ['role' => 'student'])->assertForbidden();
});

it('SEC-9 admin can filter the audit log', function () {
    $this->actingAs($this->admin)->postJson('/api/admin/users', [
        'name' => 'X Y', 'email' => 'xy@uniconnect.test', 'role' => 'student', 'password' => 'Password123!',
    ]);
    $this->actingAs($this->admin)->getJson('/api/admin/audit-logs?action=user.')
        ->assertOk()
        ->assertJsonPath('data.0.action', 'user.created')
        ->assertJsonPath('data.0.user.id', $this->admin->id);
});
