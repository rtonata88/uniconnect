// NFR-3: end-to-end message latency (REST send → MessageSent on another socket) with
//        200 receivers in one course chat. Target p95 < 500 ms.
// NFR-4: ≥ 500 concurrent WebSocket connections across the Reverb instances, stable for 2 min.
// Receivers authenticate their presence channel through /api/broadcasting/auth like the app.
import http from 'k6/http'
import { check } from 'k6'
import { Counter, Trend } from 'k6/metrics'
import { setInterval, setTimeout, clearInterval } from 'k6/timers'
import { WebSocket } from 'k6/websockets'
import { BASE, big, headers, reverbKey, socketUrl, tlsOptions, users, uuid } from './lib.js'

const e2e = new Trend('ws_e2e_latency', true)
const connected = new Counter('ws_connected')
const subscribed = new Counter('ws_subscribed')
const errors = new Counter('ws_errors')
const unexpectedClose = new Counter('ws_unexpected_close')
const received = new Counter('ws_messages_received')
const sent = new Counter('ws_messages_sent')

const HOLD_MS = Number(__ENV.HOLD_SECONDS || 120) * 1000
const RECEIVERS = Number(__ENV.RECEIVERS || 200)
const HOLDERS = Number(__ENV.HOLDERS || 300)

export const options = {
  ...tlsOptions,
  scenarios: {
    receivers: { executor: 'per-vu-iterations', vus: RECEIVERS, iterations: 1, maxDuration: `${HOLD_MS / 1000 + 60}s`, exec: 'receiver' },
    holders: { executor: 'per-vu-iterations', vus: HOLDERS, iterations: 1, maxDuration: `${HOLD_MS / 1000 + 60}s`, exec: 'holder' },
    sender: { executor: 'constant-arrival-rate', rate: 1, timeUnit: '1s', duration: `${HOLD_MS / 1000 - 40}s`, startTime: '30s', preAllocatedVUs: 2, exec: 'sender' },
  },
  thresholds: {
    ws_e2e_latency: ['p(95)<500'],
    ws_errors: ['count<1'],
    ws_unexpected_close: ['count<1'],
    ws_connected: [`count>=${RECEIVERS + HOLDERS}`],
  },
}

export function setup() {
  return { key: reverbKey() }
}

function open(key, token, channel, onEvent) {
  const ws = new WebSocket(socketUrl(key))
  let closing = false
  ws.onerror = () => errors.add(1)
  ws.onclose = () => {
    if (!closing) unexpectedClose.add(1)
  }
  ws.onmessage = (m) => {
    const e = JSON.parse(m.data)
    if (e.event === 'pusher:connection_established') {
      connected.add(1)
      const socketId = JSON.parse(e.data).socket_id
      const auth = http.post(`${BASE}/api/broadcasting/auth`, JSON.stringify({ socket_id: socketId, channel_name: channel }), { ...headers(token), tags: { name: 'POST /broadcasting/auth' } })
      check(auth, { 'channel auth 200': (r) => r.status === 200 })
      const body = auth.json()
      ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel, auth: body.auth, channel_data: body.channel_data } }))
    } else if (e.event === 'pusher_internal:subscription_succeeded') {
      subscribed.add(1)
    } else if (e.event === 'pusher:ping') {
      ws.send(JSON.stringify({ event: 'pusher:pong', data: {} }))
    } else if (e.event === 'pusher:error') {
      errors.add(1)
    } else {
      onEvent?.(e)
    }
  }
  // keep the connection alive like pusher-js does
  const ping = setInterval(() => ws.send(JSON.stringify({ event: 'pusher:ping', data: {} })), 25000)
  setTimeout(() => {
    closing = true
    clearInterval(ping)
    ws.close()
  }, HOLD_MS)
}

export function receiver(data) {
  const u = users[(__VU - 1) % RECEIVERS] // the first 260 load-test students are in the big course chat
  open(data.key, u.token, `presence-${big.channel}`, (e) => {
    if (e.event === 'MessageSent') {
      const msg = JSON.parse(e.data).message
      const m = /lt:(\d+)/.exec(msg.body || '')
      if (m) {
        received.add(1)
        e2e.add(Date.now() - Number(m[1]))
      }
    }
  })
}

export function holder(data) {
  const u = users[RECEIVERS + ((__VU - 1) % HOLDERS)]
  open(data.key, u.token, `private-user.${u.id}`)
}

export function sender() {
  const res = http.post(
    `${BASE}/api/conversations/${big.conversation_id}/messages`,
    JSON.stringify({ client_uuid: uuid(), body: `lt:${Date.now()}` }),
    { ...headers(big.sender_token), tags: { name: 'POST /conversations/{id}/messages (ws test)' } },
  )
  if (res.status === 201) sent.add(1)
}
