<?php

use App\Support\Registry\ServiceRegistry;
use App\Support\Registry\TraefikKvWriter;

beforeEach(function () {
    $this->registry = app(ServiceRegistry::class);
    $this->redis = $this->registry->redis();
    $this->redis->command('flushdb');
});

afterEach(fn () => $this->redis->command('flushdb'));

function beat(ServiceRegistry $r, string $service, int $port): void
{
    $r->heartbeat([
        'service' => $service, 'host' => '127.0.0.1', 'port' => $port,
        'url' => "http://127.0.0.1:{$port}", 'pid' => 1, 'started_at' => now()->toIso8601ZuluString(),
    ]);
}

it('stores heartbeats with a TTL and groups them by service', function () {
    beat($this->registry, 'api', 8002);
    beat($this->registry, 'api', 8001);
    beat($this->registry, 'reverb', 8101);

    $all = $this->registry->all();
    expect(array_keys($all))->toBe(['api', 'reverb'])
        ->and(array_column($all['api'], 'port'))->toBe([8001, 8002])
        ->and($all['api'][0])->toHaveKeys(['service', 'host', 'port', 'url', 'pid', 'version', 'started_at', 'last_seen', 'load']);

    $ttl = $this->redis->command('ttl', ['registry:api:127.0.0.1:8001']);
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(15);
});

it('removes an instance on deregister', function () {
    beat($this->registry, 'api', 8001);
    $this->registry->deregister('api', '127.0.0.1', 8001);
    expect($this->registry->instances('api'))->toBe([]);
});

it('writes a contiguous Traefik server list and removes stale indexes', function () {
    $writer = app(TraefikKvWriter::class);
    foreach ([8001, 8002, 8003] as $p) {
        beat($this->registry, 'api', $p);
    }
    $writer->sync();

    $base = 'traefik/http/services/api/loadBalancer/';
    expect($this->redis->command('get', [$base.'servers/2/url']))->toBe('http://127.0.0.1:8003')
        ->and($this->redis->command('get', [$base.'healthCheck/path']))->toBe('/api/health');

    // instance 8002 stops: list shrinks to 0..1 and index 2 is deleted
    $this->registry->deregister('api', '127.0.0.1', 8002);
    $writer->sync();

    expect($this->redis->command('get', [$base.'servers/0/url']))->toBe('http://127.0.0.1:8001')
        ->and($this->redis->command('get', [$base.'servers/1/url']))->toBe('http://127.0.0.1:8003')
        ->and($this->redis->command('exists', [$base.'servers/2/url']))->toBe(0);

    // no instances: the service disappears from Traefik
    $this->registry->deregister('api', '127.0.0.1', 8001);
    $this->registry->deregister('api', '127.0.0.1', 8003);
    $writer->sync();
    expect($this->registry->scan('traefik/http/services/api/*'))->toBe([]);
});
