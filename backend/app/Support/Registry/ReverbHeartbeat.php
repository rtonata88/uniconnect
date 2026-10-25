<?php

namespace App\Support\Registry;

use Illuminate\Support\Facades\Log;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use React\EventLoop\Loop;
use Throwable;

/**
 * Registers a Reverb instance in the service registry from inside its own event
 * loop, so the heartbeat stops the moment the server stops. Also reports the
 * instance's live connection count and logs which instance each socket uses (DIST-3).
 */
class ReverbHeartbeat
{
    private array $info;

    public function __construct(private ServiceRegistry $registry) {}

    public function start(): void
    {
        $host = (string) config('uniconnect.instance.host');
        $port = (int) (config('uniconnect.instance.port') ?: config('reverb.servers.reverb.port'));
        $this->info = [
            'service' => 'reverb',
            'host' => $host,
            'port' => $port,
            'url' => "http://{$host}:{$port}",
            'pid' => getmypid(),
            'started_at' => now()->toIso8601ZuluString(),
        ];

        // first beat once the loop is running (the socket is bound by then)
        Loop::get()->futureTick(fn () => $this->beat());
        Loop::get()->addPeriodicTimer((int) config('uniconnect.registry.heartbeat_seconds'), fn () => $this->beat());

        // Symfony's signal handler exits the process after Reverb disconnects clients
        register_shutdown_function(function () use ($host, $port) {
            try {
                $this->registry->deregister('reverb', $host, $port);
            } catch (Throwable) {
            }
        });

        app('events')->listen(MessageReceived::class, function (MessageReceived $event) use ($host, $port) {
            if (str_contains($event->message, 'pusher:subscribe')) {
                $channel = json_decode($event->message, true)['data']['channel'] ?? '?';
                Log::channel('stderr')->info("[reverb {$host}:{$port}] socket {$event->connection->id()} subscribed to {$channel}");
            }
        });
    }

    private function beat(): void
    {
        try {
            $this->registry->heartbeat($this->info, ['connections' => $this->connections()]);
        } catch (Throwable $e) {
            Log::channel('stderr')->warning('reverb heartbeat failed: '.$e->getMessage());
        }
    }

    private function connections(): int
    {
        $count = 0;
        foreach (app(ApplicationProvider::class)->all() as $application) {
            $count += count(app(ChannelManager::class)->for($application)->connections());
        }

        return $count;
    }
}
