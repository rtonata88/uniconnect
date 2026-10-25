<?php

namespace App\Support\System;

use App\Http\Middleware\AddServedBy;
use App\Support\Registry\ServiceRegistry;
use App\Support\Registry\TraefikKvWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * DIST-6 System Status: instances from the service registry (PRD 5.2) plus health
 * checks of every shared component. Each check is isolated so one failure never
 * hides the others.
 */
class SystemStatus
{
    public function __construct(private ServiceRegistry $registry) {}

    public function snapshot(): array
    {
        return [
            'generated_at' => now()->toIso8601ZuluString('millisecond'),
            'served_by' => AddServedBy::identity(),
            'instances' => $this->instances(),
            'gateway' => $this->gateway(),
            'checks' => [
                'mysql_primary' => $this->check(fn () => $this->primary()),
                'mysql_replica' => $this->check(fn () => $this->replica()),
                'redis' => $this->check(fn () => $this->redis()),
                'minio' => $this->check(fn () => $this->minio()),
            ],
            'queues' => $this->check(fn () => $this->queues()),
            'websockets' => $this->websockets(),
        ];
    }

    private function check(callable $fn): array
    {
        $t = microtime(true);
        try {
            $result = $fn();
            $result['ok'] ??= true;
        } catch (Throwable $e) {
            $result = ['ok' => false, 'detail' => Str::limit($e->getMessage(), 200)];
        }
        $result['latency_ms'] = round((microtime(true) - $t) * 1000, 1);

        return $result;
    }

    /** @return array<string, list<array>> */
    public function instances(): array
    {
        $ttl = (int) config('uniconnect.registry.ttl_seconds');
        $out = [];
        foreach ($this->registry->all() as $service => $list) {
            foreach ($list as $i) {
                $lastSeen = Carbon::parse($i['last_seen']);
                $age = $lastSeen->diffInMilliseconds(now()) / 1000;
                $out[$service][] = [
                    ...$i,
                    'status' => $age <= config('uniconnect.registry.heartbeat_seconds') * 2 ? 'healthy' : ($age <= $ttl ? 'stale' : 'down'),
                    'heartbeat_age_seconds' => round($age, 1),
                    'uptime_seconds' => (int) Carbon::parse($i['started_at'])->diffInSeconds(now()),
                ];
            }
        }

        return $out;
    }

    /** What Traefik actually routes to (written by registry:sync, read back from Redis KV). */
    private function gateway(): array
    {
        $kv = [];
        foreach (array_keys(config('uniconnect.registry.routed')) as $service) {
            $keys = $this->registry->scan(TraefikKvWriter::ROOT.$service.'/loadBalancer/servers/*');
            sort($keys, SORT_NATURAL);
            $kv[$service] = $keys ? array_values($this->registry->redis()->command('mget', [$keys])) : [];
        }
        // Traefik's own view (health-checked server states), if its API is reachable
        $traefik = null;
        try {
            $res = Http::timeout(1)->get(config('uniconnect.gateway_api').'/api/http/services');
            if ($res->ok()) {
                $traefik = collect($res->json())->filter(fn ($s) => str_ends_with($s['name'] ?? '', '@redis'))
                    ->mapWithKeys(fn ($s) => [$s['name'] => $s['serverStatus'] ?? []])->all();
            }
        } catch (Throwable) {
        }

        return ['routed' => $kv, 'traefik' => $traefik];
    }

    private function primary(): array
    {
        $pdo = DB::connection()->getPdo();
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        $detail = ['version' => $version];
        if (DB::connection()->getDriverName() === 'mysql') {
            $detail['gtid_executed'] = Str::limit((string) $pdo->query('SELECT @@GLOBAL.gtid_executed')->fetchColumn(), 80);
            $detail['host'] = config('database.connections.mysql.write.host.0').':'.config('database.connections.mysql.write.port');
        }

        return ['ok' => true, ...$detail];
    }

    /**
     * DIST-4 replication: the replica's own status plus a measured lag (write a token on
     * the primary, poll the replica until it appears).
     */
    private function replica(): array
    {
        $conn = DB::connection();
        if ($conn->getDriverName() !== 'mysql') {
            return ['ok' => true, 'detail' => 'no replica (sqlite)'];
        }
        $read = DB::connection('mysql_replica')->getPdo();
        $status = $read->query('SHOW REPLICA STATUS')->fetch(\PDO::FETCH_ASSOC) ?: [];
        $io = ($status['Replica_IO_Running'] ?? 'No') === 'Yes';
        $sql = ($status['Replica_SQL_Running'] ?? 'No') === 'Yes';

        $token = Str::random(32);
        $written = microtime(true);
        $conn->table('replication_probes')->insert(['token' => $token, 'written_at' => now()->format('Y-m-d H:i:s.u')]);
        $measured = null;
        $stmt = $read->prepare('SELECT 1 FROM replication_probes WHERE token = ?');
        for ($i = 0; $i < 100; $i++) {          // up to ~2 s
            $stmt->execute([$token]);
            if ($stmt->fetchColumn()) {
                $measured = round((microtime(true) - $written) * 1000, 1);
                break;
            }
            usleep(20_000);
        }
        $conn->table('replication_probes')->where('written_at', '<', now()->subMinutes(10))->delete();

        return [
            'ok' => $io && $sql,
            'host' => config('database.connections.mysql_replica.host').':'.config('database.connections.mysql_replica.port'),
            'io_running' => $io,
            'sql_running' => $sql,
            'seconds_behind_source' => isset($status['Seconds_Behind_Source']) ? (int) $status['Seconds_Behind_Source'] : null,
            'measured_lag_ms' => $measured,
            'last_error' => ($status['Last_Error'] ?? '') ?: null,
        ];
    }

    private function redis(): array
    {
        $info = Redis::connection()->command('info');
        $server = $info['Server'] ?? $info;
        $memory = $info['Memory'] ?? $info;

        return [
            'ok' => (bool) Redis::connection()->command('ping'),
            'version' => $server['redis_version'] ?? null,
            'used_memory' => $memory['used_memory_human'] ?? null,
            'keyspace_notifications' => $this->registry->redis()->command('config', ['GET', 'notify-keyspace-events'])['notify-keyspace-events'] ?? null,
        ];
    }

    private function minio(): array
    {
        $endpoint = rtrim((string) config('filesystems.disks.s3.endpoint'), '/');
        if (! $endpoint) {
            return ['ok' => false, 'detail' => 'no endpoint configured'];
        }
        $live = Http::timeout(2)->get("{$endpoint}/minio/health/live");

        return ['ok' => $live->successful(), 'endpoint' => $endpoint, 'bucket' => config('filesystems.disks.s3.bucket')];
    }

    private function queues(): array
    {
        $redis = Redis::connection();
        $sizes = [];
        foreach (['broadcasts', 'default', 'push'] as $q) {
            $sizes[$q] = (int) $redis->command('llen', ["queues:{$q}"]) + (int) $redis->command('zcard', ["queues:{$q}:delayed"]);
        }
        $failed = DB::table('failed_jobs')->count();

        return ['ok' => $failed < 100, 'pending' => $sizes, 'failed' => $failed];
    }

    private function websockets(): array
    {
        $reverb = $this->instances()['reverb'] ?? [];

        return [
            'ok' => count($reverb) > 0,
            'connections' => array_sum(array_map(fn ($i) => (int) ($i['connections'] ?? 0), $reverb)),
            'per_instance' => collect($reverb)->mapWithKeys(fn ($i) => ["{$i['host']}:{$i['port']}" => (int) ($i['connections'] ?? 0)])->all(),
        ];
    }
}
