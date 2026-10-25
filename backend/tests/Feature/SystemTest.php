<?php

use App\Models\Assignment;
use App\Models\Submission;
use App\Models\User;
use App\Support\Registry\ServiceRegistry;

beforeEach(function () {
    $this->registry = app(ServiceRegistry::class);
    $this->registry->redis()->command('flushdb');
});
afterEach(fn () => $this->registry->redis()->command('flushdb'));

it('DIST-6 shows every registered instance with status, heartbeat and uptime', function () {
    foreach ([['api', 8001], ['api', 8002], ['reverb', 8101]] as [$svc, $port]) {
        $this->registry->heartbeat(['service' => $svc, 'host' => '10.0.0.5', 'port' => $port, 'url' => "http://10.0.0.5:{$port}", 'pid' => 1, 'started_at' => now()->subMinutes(3)->toIso8601ZuluString()], ['connections' => 7]);
    }
    $res = $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/system')->assertOk()
        ->assertHeader('X-Served-By');

    expect($res->json('instances.api'))->toHaveCount(2)
        ->and($res->json('instances.api.0.status'))->toBe('healthy')
        ->and($res->json('instances.api.0.uptime_seconds'))->toBeGreaterThanOrEqual(179)
        ->and($res->json('instances.api.0'))->toHaveKeys(['host', 'port', 'hostname', 'pid', 'last_seen', 'heartbeat_age_seconds'])
        ->and($res->json('websockets.connections'))->toBe(7)
        ->and($res->json('checks'))->toHaveKeys(['mysql_primary', 'mysql_replica', 'redis', 'minio'])
        ->and($res->json('checks.redis.ok'))->toBeTrue()
        ->and($res->json('queues'))->toHaveKeys(['pending', 'failed'])
        ->and($res->json('counts.users'))->toBe(1);
});

it('DIST-6 system status is admin only', function () {
    $this->actingAs(User::factory()->lecturer()->create())->getJson('/api/admin/system')->assertForbidden();
});

it('LMS-11 student dashboard lists upcoming deadlines with submission state', function () {
    [$course, , $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->create(['due_at' => now()->addDays(2), 'title' => 'Soon']);
    Assignment::factory()->for($course)->create(['due_at' => now()->addDays(30)]);
    $res = $this->actingAs($student)->getJson('/api/dashboard')->assertOk();
    expect($res->json('upcoming_deadlines'))->toHaveCount(1)
        ->and($res->json('upcoming_deadlines.0.title'))->toBe('Soon')
        ->and($res->json('upcoming_deadlines.0.submitted'))->toBeFalse()
        ->and($res->json())->toHaveKeys(['recent_announcements', 'unread_chats']);
});

it('PRD 10.1 lecturer dashboard lists submissions to grade', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->create();
    Submission::factory()->for($a)->create(['student_id' => $student->id]);
    $res = $this->actingAs($lecturer)->getJson('/api/dashboard')->assertOk();
    expect($res->json('to_grade.0.ungraded'))->toBe(1)->and($res->json('recent_submissions'))->toHaveCount(1);
});

it('DIST-1 the REST API is documented with Scribe (OpenAPI)', function () {
    $spec = base_path('../docs/api/openapi.yaml');
    expect(is_file($spec))->toBeTrue();
    $yaml = (string) file_get_contents($spec);
    foreach (['/api/auth/login', '/api/courses', '/api/conversations/{conversation_id}/messages', '/api/sync', '/api/admin/system'] as $path) {
        expect($yaml)->toContain($path);
    }
})->skip(fn () => ! is_file(base_path('../docs/api/openapi.yaml')), 'run make docs first');

it('DIST-2 / DIST-5 every response names the instance that served it', function () {
    $this->getJson('/api/health')->assertHeader('X-Served-By', '127.0.0.1:8001');
});
