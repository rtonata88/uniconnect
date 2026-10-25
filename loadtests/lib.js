// Shared helpers for the k6 scripts (PRD 12.2). Tokens come from
// `php artisan loadtest:tokens` (loadtests/.out/tokens.json) so k6 never hits the login limits.
import http from 'k6/http'
import { SharedArray } from 'k6/data'

export const BASE = (__ENV.BASE_URL || 'https://localhost').replace(/\/$/, '')
export const WS_BASE = BASE.replace(/^http/, 'ws')
const data = JSON.parse(open('./.out/tokens.json'))
export const users = new SharedArray('users', () => data.users)
export const big = data.big

export const tlsOptions = { insecureSkipTLSVerify: true }

export function headers(token, extra = {}) {
  return { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json', ...extra } }
}

export function get(path, token, name) {
  return http.get(`${BASE}/api${path}`, { ...headers(token), tags: { name: name || path } })
}

export function uuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })
}

/** Pusher-protocol socket URL for Reverb through the gateway. */
export function socketUrl(key) {
  return `${WS_BASE}/app/${key}?protocol=7&client=k6&version=8.4.0`
}

export function reverbKey() {
  return http.get(`${BASE}/api/config`).json('reverb.key')
}
