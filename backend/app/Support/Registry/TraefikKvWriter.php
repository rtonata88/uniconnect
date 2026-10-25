<?php

namespace App\Support\Registry;

/**
 * Translates registry heartbeats into Traefik's Redis KV layout:
 *
 *   traefik/http/services/{svc}/loadBalancer/servers/{i}/url        (contiguous 0..n-1)
 *   traefik/http/services/{svc}/loadBalancer/healthCheck/path|interval|timeout
 *
 * Only changed keys are written, so Traefik reloads only when membership changes.
 */
class TraefikKvWriter
{
    public const ROOT = 'traefik/http/services/';

    public function __construct(private ServiceRegistry $registry) {}

    /**
     * Traefik's Redis provider watches keys through keyspace notifications.
     */
    public function ensureKeyspaceNotifications(): void
    {
        $redis = $this->registry->redis();
        $current = $redis->command('config', ['GET', 'notify-keyspace-events']);
        $flags = is_array($current) ? (string) (array_values($current)[1] ?? $current['notify-keyspace-events'] ?? '') : '';
        if (! str_contains($flags, 'K') || ! str_contains($flags, 'A')) {
            $redis->command('config', ['SET', 'notify-keyspace-events', 'KEA']);
        }
    }

    /**
     * @return array<string, list<string>> service => server URLs now routed
     */
    public function sync(): array
    {
        $redis = $this->registry->redis();
        $all = $this->registry->all();
        $summary = [];

        foreach (config('uniconnect.registry.routed') as $service => $healthPath) {
            $urls = collect($all[$service] ?? [])->pluck('url')->filter()->unique()->sort(SORT_NATURAL)->values()->all();
            $summary[$service] = $urls;
            $base = self::ROOT.$service.'/loadBalancer/';

            $desired = [];
            foreach ($urls as $i => $url) {
                $desired[$base."servers/{$i}/url"] = $url;
            }
            if ($urls !== []) {
                $desired[$base.'healthCheck/path'] = $healthPath;
                $desired[$base.'healthCheck/interval'] = config('uniconnect.registry.health_interval');
                $desired[$base.'healthCheck/timeout'] = config('uniconnect.registry.health_timeout');
            }

            $existingKeys = $this->registry->scan(self::ROOT.$service.'/*');
            $existing = [];
            if ($existingKeys !== []) {
                $existing = array_combine($existingKeys, $redis->command('mget', [$existingKeys]));
            }

            // stale indexes (list shrank) and leftovers
            $stale = array_values(array_diff(array_keys($existing), array_keys($desired)));
            // Add new servers before removing old ones so the list is never empty mid-update.
            foreach ($desired as $key => $value) {
                if (($existing[$key] ?? null) !== $value) {
                    $redis->command('set', [$key, $value]);
                }
            }
            if ($stale !== []) {
                $redis->command('del', $stale);
            }
        }

        return $summary;
    }
}
