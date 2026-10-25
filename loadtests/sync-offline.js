// NFR-6: a client that was offline replays 50 messages (the outbox sends them in order; a flaky
// network makes it resend some). Then it syncs: all delivered, no duplicates, order preserved.
import http from 'k6/http'
import { check } from 'k6'
import { Counter } from 'k6/metrics'
import { BASE, get, headers, tlsOptions, users, uuid } from './lib.js'

const ok = new Counter('nfr6_ok')

export const options = { ...tlsOptions, vus: 1, iterations: 1, thresholds: { nfr6_ok: ['count==1'], checks: ['rate==1'] } }

export default function () {
  const u = users[499]
  const run = `nfr6-${Date.now()}`
  const before = get('/sync', u.token, 'GET /sync').json('cursor')
  const queue = Array.from({ length: 50 }, (_, i) => ({ client_uuid: uuid(), body: `${run} #${String(i + 1).padStart(2, '0')}` }))
  let retried = 0
  for (const [i, m] of queue.entries()) {
    const post = () => http.post(`${BASE}/api/conversations/${u.conversation_id}/messages`, JSON.stringify(m), { ...headers(u.token), tags: { name: 'POST message (replay)' } })
    const first = post()
    check(first, { 'first send 201': (r) => r.status === 201 })
    if (i % 5 === 0) {
      // lost response: the outbox retries with the same client_uuid
      const again = post()
      retried++
      check(again, { 'retry is idempotent (200, same id)': (r) => r.status === 200 && r.json('data.id') === first.json('data.id') })
    }
  }
  // the other device catches up through delta sync
  let cursor = before
  let got = []
  for (let i = 0; i < 10; i++) {
    const page = http.get(`${BASE}/api/sync?since=${encodeURIComponent(cursor)}`, { ...headers(u.token), tags: { name: 'GET /sync' } }).json()
    got = got.concat(page.messages.filter((m) => (m.body || '').startsWith(run)))
    cursor = page.cursor
    if (!page.has_more) break
  }
  const ids = got.map((m) => m.id)
  const unique = new Set(ids).size
  const bodies = got.sort((a, b) => a.id - b.id).map((m) => m.body)
  const inOrder = bodies.every((b, i) => b === queue[i].body)
  const pass = check(null, {
    'all 50 delivered': () => unique === 50,
    'no duplicates': () => ids.length === unique,
    'order preserved': () => inOrder,
  })
  if (pass) ok.add(1)
  console.log(JSON.stringify({ sent: 50, retried, synced: ids.length, unique, inOrder }))
}
