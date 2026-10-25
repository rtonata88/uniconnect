import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { api } from '@/lib/api'

let echo: Echo<'reverb'> | null = null
let config: { key: string } | null = null

/** Runtime client config (Reverb key, VAPID key) so one build works on any host. */
export async function loadConfig() {
  const { data } = await api.get<{ reverb: { key: string }; vapid_public_key: string | null }>('/config')
  config = data.reverb
  return data
}

/**
 * Laravel Echo over Reverb. The socket goes to the same origin as the app; Traefik
 * routes /app/* to any live Reverb instance (DIST-3). Channel auth uses the bearer token.
 */
export async function connectEcho(): Promise<Echo<'reverb'>> {
  if (echo) return echo
  if (!config) await loadConfig()
  const secure = location.protocol === 'https:'
  const port = Number(location.port) || (secure ? 443 : 80)
  echo = new Echo({
    broadcaster: 'reverb',
    Pusher,
    key: config!.key,
    wsHost: location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: { auth: string; channel_data?: string } | null) => void) => {
        api
          .post<{ auth: string; channel_data?: string }>('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
          .then((res) => callback(null, res.data))
          .catch((err) => callback(err as Error, null))
      },
    }),
  })
  return echo
}

export function getEcho() {
  return echo
}

export function socketId(): string | undefined {
  return echo?.socketId()
}

export function disconnectEcho() {
  echo?.disconnect()
  echo = null
}
