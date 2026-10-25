<?php

namespace App\Support\Notify;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * NOT-3: push only goes to users who are not active in the app. "Active" means
 * connected to the presence-online channel on any Reverb instance; Reverb's HTTP
 * API answers for the whole cluster (Redis scaling). Cached for a few seconds.
 */
class Presence
{
    /** @return list<int> */
    public function onlineUserIds(): array
    {
        return Cache::remember('presence:online', 5, function () {
            try {
                $driver = Broadcast::driver();
                if (! method_exists($driver, 'getPusher')) {
                    return [];
                }
                $res = $driver->getPusher()->get('/channels/presence-online/users');
                $users = is_array($res) ? ($res['users'] ?? []) : ($res->users ?? []);

                return collect($users)->map(fn ($u) => (int) (is_array($u) ? $u['id'] : $u->id))->all();
            } catch (Throwable) {
                return []; // unknown: rather send a push than lose it
            }
        });
    }

    public function isOnline(int $userId): bool
    {
        return in_array($userId, $this->onlineUserIds(), true);
    }
}
