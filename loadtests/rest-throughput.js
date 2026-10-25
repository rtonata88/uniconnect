// NFR-7 helper: closed-model load without think time to measure throughput (requests/s)
// at a given number of API replicas. Run by scale.sh at 2 and then 3 replicas.
import { check } from 'k6'
import { Counter } from 'k6/metrics'
import { get, tlsOptions, users } from './lib.js'

export const options = {
  ...tlsOptions,
  scenarios: { throughput: { executor: 'constant-vus', vus: Number(__ENV.VUS || 96), duration: __ENV.DURATION || '40s' } },
  thresholds: { http_req_failed: ['rate<0.01'] }, // a run with errors is not a valid throughput figure
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
}

const nonOk = new Counter('non_200')
let i = 0
export default function () {
  // load-test tokens are exempt from per-user limits (D60); still spread over many users
  const u = users[(__VU * 37 + i++) % users.length]
  const res = i % 2 ? get(`/courses/${u.course_id}`, u.token, 'GET /courses/{id}') : get(`/courses/${u.course_id}/assignments`, u.token, 'GET /courses/{id}/assignments')
  check(res, { 'status is 200': (r) => r.status === 200 })
  if (res.status !== 200) nonOk.add(1, { status: String(res.status) })
}
