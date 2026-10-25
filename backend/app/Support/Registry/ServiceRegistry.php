<?php

namespace App\Support\Registry;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed service registry (PRD 5.2).
 *
 * Every instance writes `registry:{service}:{host}:{port}` with a short TTL; a
 * stopped instance disappears on its own. Consumers: `registry:sync` (Traefik
 * KV) and GET /api/admin/system.
 */
class ServiceRegistry
{
    public const PREFIX = 'registry:';

    public function redis(): Connection
    {
        return Redis::connection(config('uniconnect.registry.connection'));
    }

    public static function key(string $service, string $host, string|int $port): string
    {
        return self::PREFIX."{$service}:{$host}:{$port}";
    }

    /**
     * @param  array{service:string,host:string,port:int|string|null,url:?string,pid:int,started_at:string}  $info
     */
    public function heartbeat(array $info, array $extra = []): void
    {
        $now = now()->toIso8601ZuluString('millisecond');
        $value = array_merge([
            'version' => config('uniconnect.instance.version'),
            'hostname' => config('uniconnect.instance.hostname'),
            'load' => function_exists('sys_getloadavg') ? round(sys_getloadavg()[0] ?? 0, 2) : null,
        ], $info, $extra, ['last_seen' => $now]);

        $this->redis()->command('set', [
            self::key($info['service'], $info['host'], $info['port'] ?? $info['pid']),
            json_encode($value),
            ['EX' => (int) config('uniconnect.registry.ttl_seconds')],
        ]);
    }

    public function deregister(string $service, string $host, string|int $port): void
    {
        $this->redis()->command('del', [self::key($service, $host, $port)]);
    }

    /**
     * All live instances, grouped by service and sorted by host:port.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function all(): array
    {
        $keys = $this->scan(self::PREFIX.'*');
        if ($keys === []) {
            return [];
        }

        $values = $this->redis()->command('mget', [$keys]);
        $grouped = [];
        foreach ($values as $raw) {
            $info = $raw ? json_decode($raw, true) : null;
            if (is_array($info) && isset($info['service'])) {
                $grouped[$info['service']][] = $info;
            }
        }
        foreach ($grouped as &$list) {
            usort($list, fn ($a, $b) => strnatcmp($a['host'].':'.$a['port'], $b['host'].':'.$b['port']));
        }
        ksort($grouped);

        return $grouped;
    }

    /** @return list<array<string, mixed>> */
    public function instances(string $service): array
    {
        return $this->all()[$service] ?? [];
    }

    /** @return list<string> */
    public function scan(string $pattern): array
    {
        $redis = $this->redis();
        $keys = [];
        $cursor = null;
        do {
            $result = $redis->scan($cursor, ['match' => $pattern, 'count' => 500]);
            if ($result === false) {
                break;
            }
            [$cursor, $batch] = $result;
            $keys = array_merge($keys, $batch);
        } while ((string) $cursor !== '0');

        return array_values(array_unique($keys));
    }
}
