# Test plan

## Objectives

1. Show that every P0 requirement of PRD §7 works (functional correctness and authorization).
2. Show that the system meets the non-functional targets of PRD §11 under load, with real measurements.
3. Show the security posture (OWASP Top 10 review, dependency audits, dynamic scan).

Results: [`TEST_REPORT.md`](TEST_REPORT.md) (generated) · raw outputs in [`results/`](results/).

## Levels and tools

| Level | Tool | Scope | How to run |
|---|---|---|---|
| Unit + feature (API) | Pest 5 (PHPUnit 13), SQLite in memory, Redis DB 15 | every endpoint of PRD §8: happy paths, validation, authorization (403/401), idempotency, late rules, quiz grading and timing, receipts, channel auth, rate limits, file policy and signed URLs, sync, notifications, system status | `make test-backend` (JUnit + Xdebug coverage) |
| Frontend unit / component | Vitest 5 + React Testing Library + fake-indexeddb | outbox order and retries, sync cursor loop and pruning, tick status component, quiz timer auto-submit, offline repository fallback, auth token storage, notification-click navigation, layout | `make test-frontend` |
| End-to-end | Playwright (Chromium) against the running stack via the gateway | PRD 12.1 scenarios E2E-1…4 + PWA installability/offline shell + phone layout | `make test-e2e` (needs `make up && make seed`) |
| Load / distributed | k6 (HTTP + WebSockets) | NFR-1…7 | `make loadtest` |
| Client performance | Lighthouse 12 (mobile) + Chrome installability API | NFR-8 | part of `make loadtest` |
| Security | `composer audit`, `npm audit`, OWASP ZAP 2.17 baseline (passive), manual OWASP review | | `make zap`, [`security-checklist.md`](security-checklist.md) |

`make test` runs backend, frontend and E2E tests and then `php artisan report:testing`.
`make loadtest` seeds the load-test data set, runs every k6 script, failover, scaling and Lighthouse, then
regenerates the report.

## Test data

- **Functional tests** build their own data with factories (`database/factories`) — every test starts from an
  empty SQLite database (`RefreshDatabase`). `tests/TestCase.php` refuses to run against anything other
  than SQLite or a `*_testing` database.
- **E2E** uses the demo seed (`make seed`) and creates uniquely named records per run (`E2E essay <id>`), so
  it can be repeated without reseeding.
- **Load tests** use `LoadTestSeeder` (500 students `lt_student001…500`, 5 lecturers, 10 courses with 50
  students each, one course chat with 260 members, 1 000 messages) and tokens from
  `php artisan loadtest:tokens` (login throttling is not part of the measurement; per-user API limits stay on).

## E2E scenarios (PRD 12.1)

| ID | Scenario | Spec |
|---|---|---|
| E2E-1 | Lecturer creates an assignment → student gets a live notification → submits → lecturer grades → grade hidden until release → release → student sees grade and feedback | `frontend/e2e/01-assignment-flow.spec.ts` |
| E2E-2 | Two browser contexts: presence (online), typing indicator, message delivered in real time, sender's ticks turn blue, reply the other way | `02-realtime-chat.spec.ts` |
| E2E-3 | Student goes offline (status pill), sends 3 chat messages and a submission (pending), goes online → all sync; a lecturer sees the 3 messages once each, in order, and the submission | `03-offline-sync.spec.ts` |
| E2E-4 | Student opens a course they are not in → "Access denied"; API returns 403 | `04-access-control.spec.ts` |
| MOB-2 | Manifest + service worker; Chrome reports no installability errors; app shell opens offline | `05-pwa-mobile.spec.ts` |
| MOB-1/3 | Phone viewport: four bottom tabs ≥ 44 px, sidebar hidden, camera capture input | `05-pwa-mobile.spec.ts` |

## Load tests (PRD 12.2)

| Script | NFR | Load | Pass criteria |
|---|---|---|---|
| `rest-mixed.js` | NFR-1 | ramp to 100 VUs (15 s), 60 s steady; each VU = one user, mixed reads, 0.5–1.5 s think time | p95 < 300 ms, errors < 1 % |
| `send-messages.js` | NFR-2 | 50 senders, 60 s, one message per ~1.5 s each, across 10 course chats | p95 < 400 ms |
| `ws-latency.js` | NFR-3, NFR-4 | 200 receivers in one 260-member course chat (presence channel) + 300 sockets on private channels = 500 sockets for 120 s; one sender posts a timestamped message every second | e2e p95 < 500 ms; 500 sockets connected, 0 errors, 0 unexpected closes |
| `failover.sh` | NFR-5 | `rest-mixed` for 60 s; `pm2 stop` on one API replica after 35 s | errors < 2 %, last error within 15 s of the stop |
| `sync-offline.js` | NFR-6 | 50 messages replayed in order with every 5th one sent twice (lost response), then delta sync | 50 unique, in order, no duplicates |
| `scale.sh` + `rest-throughput.js` | NFR-7 | 64 VUs, no think time, 40 s, at 2 then 3 API replicas | throughput recorded for both |
| `lighthouse.sh` + `pwa-check.mjs` | NFR-8 | Lighthouse mobile on `/login`; Chrome installability API; offline reload | performance ≥ 80; installable |

## Entry / exit criteria

- Entry: `make up` with all PM2 processes online, `make seed` done, `make status` green.
- Exit: all automated tests pass; backend line coverage of `app/` ≥ 80 %; every P0 requirement appears in the
  traceability table of `TEST_REPORT.md`; every NFR has a measured value; failures are explained, not hidden.

## Environment

All on one machine (see `TEST_REPORT.md` → Environment): load generator, gateway, 2 API replicas
(Octane/FrankenPHP, 4 workers each), 2 Reverb replicas, Horizon, MySQL primary + replica, Redis and MinIO.
Results are therefore conservative for a real multi-machine deployment.
