<?php

use App\Models\Assignment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\PersonalAccessToken;

it('SEC-10 CORS only allows the web app origin', function () {
    config(['cors.allowed_origins' => ['https://localhost']]);
    $ok = $this->withHeaders(['Origin' => 'https://localhost', 'Access-Control-Request-Method' => 'GET'])->options('/api/health');
    expect($ok->headers->get('Access-Control-Allow-Origin'))->toBe('https://localhost');

    $evil = $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'GET'])->options('/api/health');
    // never echoed back: with one allowed origin the header stays fixed, so browsers block evil.example
    expect($evil->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example');
});

it('SEC-6 API responses carry security headers and are not cached', function () {
    $res = $this->getJson('/api/health');
    $res->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    expect($res->headers->get('Cache-Control'))->toContain('no-store');
});

it('Horizon is only served to the host machine', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/horizon/api/stats')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])->get('/horizon/api/stats')->assertForbidden();
});

it('SEC-7 limits uploads to 20 per minute', function () {
    fakeStorage();
    $user = User::factory()->create();
    foreach (range(1, 20) as $i) {
        $this->actingAs($user)->post('/api/attachments', ['file' => pdfUpload()], ['Accept' => 'application/json'])->assertCreated();
    }
    $this->actingAs($user)->post('/api/attachments', ['file' => pdfUpload()], ['Accept' => 'application/json'])->assertStatus(429);
});

it('SEC-11 expired tokens are rejected', function () {
    $user = User::factory()->create();
    $token = $user->createToken('old', ['*'], now()->subMinute())->plainTextToken;
    $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    expect(PersonalAccessToken::count())->toBe(1);
});

it('SEC-8 search input is parameterised (no SQL injection)', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['name' => "O'Brien"]);
    $this->actingAs($admin)->getJson('/api/admin/users?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($admin)->getJson('/api/admin/users?search='.urlencode("O'Brien"))->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($admin)->getJson('/api/admin/users?search=%25')->assertOk()->assertJsonCount(0, 'data'); // % is literal
});

it('SEC-8 rejects unknown roles and oversized input', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->postJson('/api/admin/users', ['name' => str_repeat('a', 500), 'email' => 'x@y.test', 'role' => 'root', 'password' => 'Password123!'])
        ->assertStatus(422)->assertJsonValidationErrors(['name', 'role']);
});

it('CSV export neutralises spreadsheet formulas', function () {
    [$course, $lecturer] = courseWithMembers();
    $evil = User::factory()->student()->create(['name' => '=HYPERLINK("http://evil")']);
    $course->members()->attach($evil->id, ['role' => 'student']);
    $a = Assignment::factory()->for($course)->create();
    $sub = Submission::factory()->for($a)->create(['student_id' => $evil->id]);
    $this->actingAs($lecturer)->patchJson("/api/submissions/{$sub->id}/grade", ['score' => 10]);
    $csv = $this->actingAs($lecturer)->get("/api/courses/{$course->id}/gradebook/export")->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK');
});

it('SEC-5 never serves uploads with an executable type', function () {
    fakeStorage();
    $user = User::factory()->create();
    foreach (['x.html' => '<script>alert(1)</script>', 'x.svg' => '<svg onload="alert(1)"/>', 'x.js' => 'alert(1)'] as $name => $body) {
        $this->actingAs($user)->post('/api/attachments', ['file' => UploadedFile::fake()->createWithContent($name, $body)], ['Accept' => 'application/json'])->assertStatus(422);
    }
});

it('error responses do not leak stack traces', function () {
    config(['app.debug' => false]);
    $user = User::factory()->create();
    $res = $this->actingAs($user)->getJson('/api/courses/999999')->assertNotFound();
    expect($res->json())->toHaveKey('message')->not->toHaveKey('trace')->not->toHaveKey('exception');
});

it('answers 401 JSON to unauthenticated API calls even without an Accept header', function () {
    $res = $this->call('DELETE', '/api/announcements/1');
    expect($res->status())->toBe(401)->and($res->json('message'))->toBe('Unauthenticated.');
});

it('404s do not reveal model class names', function () {
    $user = User::factory()->create();
    $res = $this->actingAs($user)->getJson('/api/assignments/987654')->assertNotFound();
    expect($res->json('message'))->toBe('Not found.');
});
