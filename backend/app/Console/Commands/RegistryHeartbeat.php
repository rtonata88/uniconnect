<?php

namespace App\Console\Commands;

use App\Support\Registry\ServiceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Heartbeat sidecar used by scripts/run-api.sh and scripts/run-with-heartbeat.sh.
 * Registers the instance every 5 s while it is alive (and healthy, if --health is
 * given). Deletes its key at once on SIGTERM or when the watched process exits.
 */
class RegistryHeartbeat extends Command
{
    protected $signature = 'registry:heartbeat
        {service : api | reverb | worker | scheduler}
        {--port= : Port the instance listens on (omit for worker/scheduler)}
        {--pid= : Process to watch; heartbeating stops when it exits}
        {--health= : URL that must return 2xx before the instance is advertised}';

    protected $description = 'Heartbeat an instance into the Redis service registry';

    private bool $running = true;

    public function handle(ServiceRegistry $registry): int
    {
        $service = $this->argument('service');
        $host = (string) config('uniconnect.instance.host');
        $port = $this->option('port');
        $pid = (int) ($this->option('pid') ?: getmypid());
        $health = $this->option('health');
        $keyPort = $port ?: $pid;

        $info = [
            'service' => $service,
            'host' => $host,
            'port' => $port ? (int) $port : null,
            'url' => $port ? "http://{$host}:{$port}" : null,
            'pid' => $pid,
            'started_at' => now()->toIso8601ZuluString(),
        ];

        $stop = function () use ($registry, $service, $host, $keyPort) {
            $this->running = false;
            $registry->deregister($service, $host, $keyPort);
        };
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);

        $interval = (int) config('uniconnect.registry.heartbeat_seconds');
        $registered = false;

        while ($this->running) {
            if (! $this->alive($pid)) {
                break;
            }

            $healthy = ! $health || $this->healthy($health);
            try {
                if ($healthy) {
                    $registry->heartbeat($info);
                    if (! $registered) {
                        $this->info("registered {$service} {$host}:{$keyPort}");
                    }
                    $registered = true;
                } elseif ($registered) {
                    // unhealthy: stop advertising so Traefik drops it within one sync
                    $registry->deregister($service, $host, $keyPort);
                    $registered = false;
                    $this->warn("deregistered {$service} {$host}:{$keyPort} (health check failed)");
                }
            } catch (Throwable $e) {
                $this->error('registry unavailable: '.$e->getMessage());
            }

            // boot quickly: poll every second until the first successful registration
            $wait = $registered ? $interval : 1;
            for ($i = 0; $i < $wait * 10 && $this->running; $i++) {
                usleep(100_000);
            }
        }

        try {
            $registry->deregister($service, $host, $keyPort);
        } catch (Throwable) {
            // key expires on its own within the TTL
        }
        $this->info("deregistered {$service} {$host}:{$keyPort}");

        return self::SUCCESS;
    }

    private function alive(int $pid): bool
    {
        return $pid <= 0 || posix_kill($pid, 0);
    }

    private function healthy(string $url): bool
    {
        try {
            return Http::timeout(2)->connectTimeout(1)->get($url)->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
