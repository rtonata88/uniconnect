// NFR-1: REST latency with 100 virtual users doing mixed reads.
// Target: p95 < 300 ms, error rate < 1 %.
import { check, sleep } from 'k6'
import { Counter } from 'k6/metrics'
import { get, tlsOptions, users } from './lib.js'

const servedBy = new Counter('served_by')
const nonOk = new Counter('non_200') // tagged with the status, to explain any failure

export const options = {
  ...tlsOptions,
  scenarios: {
    mixed: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '15s', target: Number(__ENV.VUS || 100) },
        { duration: __ENV.DURATION || '60s', target: Number(__ENV.VUS || 100) },
        { duration: '5s', target: 0 },
      ],
      gracefulRampDown: '5s',
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<300'],
    http_req_failed: ['rate<0.01'],
    // listed so --summary-export breaks failures down by status
    'non_200{status:429}': ['count>=0'],
    'non_200{status:502}': ['count>=0'],
    'non_200{status:503}': ['count>=0'],
    'non_200{status:500}': ['count>=0'],
    'non_200{status:0}': ['count>=0'],
  },
}

export default function () {
  const u = users[(__VU - 1) % users.length]
  const r = Math.random()
  let res
  if (r < 0.15) res = get('/courses', u.token, 'GET /courses')
  else if (r < 0.3) res = get(`/courses/${u.course_id}`, u.token, 'GET /courses/{id}')
  else if (r < 0.45) res = get(`/courses/${u.course_id}/announcements`, u.token, 'GET /courses/{id}/announcements')
  else if (r < 0.6) res = get(`/courses/${u.course_id}/assignments`, u.token, 'GET /courses/{id}/assignments')
  else if (r < 0.7) res = get(`/courses/${u.course_id}/materials`, u.token, 'GET /courses/{id}/materials')
  else if (r < 0.8) res = get('/conversations', u.token, 'GET /conversations')
  else if (r < 0.9) res = get(`/conversations/${u.conversation_id}/messages?limit=50`, u.token, 'GET /conversations/{id}/messages')
  else if (r < 0.95) res = get('/notifications', u.token, 'GET /notifications')
  else res = get('/me', u.token, 'GET /me')
  check(res, { 'status is 200': (x) => x.status === 200 })
  servedBy.add(1, { instance: res.headers['X-Served-By'] || 'none' })
  if (res.status !== 200) nonOk.add(1, { status: String(res.status) })
  sleep(0.5 + Math.random()) // ~60 requests/min per user: well under the 120/min limit
}
