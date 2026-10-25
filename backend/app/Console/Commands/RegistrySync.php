<?php

namespace App\Console\Commands;

use App\Support\Registry\TraefikKvWriter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Long-running loop (PM2 process `registry`): every 2 s rebuilds Traefik's
 * dynamic config in Redis KV from the live registry heartbeats (PRD 5.2).
 */
class RegistrySync extends Command
{
    protected $signature = 'registry:sync {--once : Run a single sync and exit}';

    protected $description = 'Write Traefik load balancer servers to Redis KV from live heartbeats';

    private bool $running = true;

    public function handle(TraefikKvWriter $writer): int
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->running = false);
        pcntl_signal(SIGINT, fn () => $this->running = false);

        $last = null;
        $sleep = (int) config('uniconnect.registry.sync_seconds');

        do {
            try {
                $writer->ensureKeyspaceNotifications();
                $summary = $writer->sync();
                $line = collect($summary)->map(fn ($urls, $svc) => "{$svc}=".count($urls))->implode(' ');
                if ($line !== $last) {
                    $this->info(now()->toTimeString().' routing '.$line.' '.json_encode($summary));
                    $last = $line;
                }
            } catch (Throwable $e) {
                $this->error('sync failed: '.$e->getMessage());
            }

            if ($this->option('once')) {
                break;
            }
            for ($i = 0; $i < $sleep * 10 && $this->running; $i++) {
                usleep(100_000);
            }
        } while ($this->running);

        return self::SUCCESS;
    }
}
