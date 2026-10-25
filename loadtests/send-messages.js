// NFR-2: POST /conversations/{id}/messages with 50 concurrent senders. Target: p95 < 400 ms.
import http from 'k6/http'
import { check, sleep } from 'k6'
import { BASE, headers, tlsOptions, users, uuid } from './lib.js'

export const options = {
  ...tlsOptions,
  scenarios: {
    senders: { executor: 'constant-vus', vus: Number(__ENV.VUS || 50), duration: __ENV.DURATION || '60s' },
  },
  thresholds: {
    'http_req_duration{name:POST /conversations/{id}/messages}': ['p(95)<400'],
    http_req_failed: ['rate<0.01'],
  },
}

export default function () {
  // senders spread over the 10 course chats (users 0..499 → 50 per course)
  const u = users[((__VU - 1) * 10) % users.length]
  const res = http.post(
    `${BASE}/api/conversations/${u.conversation_id}/messages`,
    JSON.stringify({ client_uuid: uuid(), body: `k6 message from VU ${__VU} at ${Date.now()}` }),
    { ...headers(u.token), tags: { name: 'POST /conversations/{id}/messages' } },
  )
  check(res, { 'created': (r) => r.status === 201 })
  sleep(1.2 + Math.random() * 0.6) // ≤ 50/min per user (limit 60/min)
}
