#!/usr/bin/env node
// DIST-3 check: two users connected to DIFFERENT Reverb instances chat in real time.
// Usage: node scripts/verify-realtime.mjs [userA] [userB]   (or: make verify-realtime)
// Each Reverb instance logs "socket <id> subscribed to <channel>" (ReverbHeartbeat), which is
// how we know which instance a socket landed on.
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const env = Object.fromEntries(fs.readFileSync(path.join(root, 'backend/.env'), 'utf8').split('\n').filter((l) => /^[A-Z_]+=/.test(l)).map((l) => [l.slice(0, l.indexOf('=')), l.slice(l.indexOf('=') + 1).replace(/^"|"$/g, '')]))
const base = (process.env.BASE_URL ?? env.APP_URL ?? 'https://localhost').replace(/\/$/, '')
const [emailA = 'mziezo@uniconnect.test', emailB = 'student1@uniconnect.test'] = process.argv.slice(2)
const password = process.env.PASSWORD ?? 'Password123!'
const logFiles = ['reverb.out.log', 'reverb.err.log'].map((f) => path.join(root, 'storage/logs', f))

const j = async (p, opts = {}) => {
  const r = await fetch(base + p, { ...opts, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(opts.headers ?? {}) } })
  const body = await r.json().catch(() => ({}))
  if (!r.ok) throw new Error(`${opts.method ?? 'GET'} ${p} → ${r.status} ${body.message ?? ''}`)
  return { body, headers: r.headers }
}
const login = async (email) => (await j('/api/auth/login', { method: 'POST', body: JSON.stringify({ email, password, device_name: 'verify-realtime' }) })).body.token
const instanceOf = (socketId) => {
  for (const f of logFiles) {
    if (!fs.existsSync(f)) continue
    const m = fs.readFileSync(f, 'utf8').match(new RegExp(`\\[reverb ([^\\]]+)\\] socket ${socketId.replace('.', '\\.')} subscribed`))
    if (m) return m[1]
  }
  return null
}

const { reverb } = (await j('/api/config')).body
const [ta, tb] = [await login(emailA), await login(emailB)]
const conv = (await j('/api/conversations', { method: 'POST', headers: { Authorization: `Bearer ${tb}` }, body: JSON.stringify({ type: 'direct', user_id: (await j('/api/me', { headers: { Authorization: `Bearer ${ta}` } })).body.data.id }) })).body.data

function connect(token, onMessage) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`${base.replace(/^http/, 'ws')}/app/${reverb.key}?protocol=7&client=js&version=8.4.0`)
    const timer = setTimeout(() => reject(new Error('socket timeout')), 10000)
    ws.onerror = (e) => reject(e)
    ws.onmessage = async (m) => {
      const e = JSON.parse(m.data)
      if (e.event === 'pusher:connection_established') {
        ws.socketId = JSON.parse(e.data).socket_id
        const channel = `presence-${conv.channel}`
        const auth = (await j('/api/broadcasting/auth', { method: 'POST', headers: { Authorization: `Bearer ${token}` }, body: JSON.stringify({ socket_id: ws.socketId, channel_name: channel }) })).body
        ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel, auth: auth.auth, channel_data: auth.channel_data } }))
      } else if (e.event === 'pusher_internal:subscription_succeeded') {
        clearTimeout(timer)
        await new Promise((r) => setTimeout(r, 300)) // let the instance write its log line
        ws.instance = instanceOf(ws.socketId)
        resolve(ws)
      } else if (e.event === 'MessageSent') onMessage?.(JSON.parse(e.data).message)
    }
  })
}

const latencies = []
const a = await connect(ta)
let b
for (let i = 0; i < 8; i++) {
  const candidate = await connect(tb, (msg) => {
    const sent = Number(String(msg.body).split('@')[1])
    if (sent) latencies.push(Date.now() - sent)
  })
  if (candidate.instance && candidate.instance !== a.instance) {
    b = candidate
    break
  }
  candidate.close()
}
console.log(`A ${emailA} → Reverb ${a.instance ?? '?'}   B ${emailB} → Reverb ${b?.instance ?? '(same instance only — is REVERB scaled to 2+?)'}`)
if (!b) process.exit(1)

for (let i = 0; i < 5; i++) {
  const r = await j(`/api/conversations/${conv.id}/messages`, { method: 'POST', headers: { Authorization: `Bearer ${ta}`, 'X-Socket-ID': a.socketId }, body: JSON.stringify({ client_uuid: crypto.randomUUID(), body: `verify ${i}@${Date.now()}` }) })
  process.stdout.write(`sent ${i} via ${r.headers.get('x-served-by')}  `)
  await new Promise((res) => setTimeout(res, 300))
}
await new Promise((res) => setTimeout(res, 1000))
a.close()
b.close()
console.log(`\nB received ${latencies.length}/5 across instances; latency ms: ${latencies.join(', ')}`)
process.exit(latencies.length === 5 ? 0 : 1)
