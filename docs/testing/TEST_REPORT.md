# UniConnect — testing and performance report

_Generated Sun, Oct 25, 2026 9:43 AM by `php artisan report:testing` from the files in `docs/testing/results/`. Every number below was measured on the machine described; nothing is estimated. A dash means the test was not run._

## Environment

| | |
|---|---|
| OS | macOS 26.6.2 (arm64) |
| CPU | Apple M5 Pro, 15 cores |
| RAM | 24 GB |
| PHP (CLI: tests, Reverb, Horizon) | 8.4.27 |
| API server | Laravel Octane + FrankenPHP v1.13.3 PHP 8.5.12 |
| Laravel | 13.38.0 |
| MySQL | 8.4.13 (primary + GTID replica) |
| Redis | 8.6.0 |
| Node | v22.22.1 |
| Traefik | 3.7.16 |
| k6 | k6 v2.3.0 (commit/devel, go1.27.1, darwin/arm64) |
| Topology during load tests | 2 API replicas (Octane, 4 workers each), 2 Reverb replicas, 1 Horizon, MySQL primary + replica — all on this one machine |

## Automated tests

| Suite | Tests | Passed | Failed | Skipped | Time | Coverage |
|---|---:|---:|---:|---:|---:|---|
| Backend (Pest) | 161 | 161 | 0 | 0 | 12.6 s | 81.8 % lines of `app/` |
| Frontend (Vitest) | 34 | 34 | 0 | 0 | 0.3 s | 14.3 % lines of `src/` (excl. generated UI kit) |
| End-to-end (Playwright) | 6 | 6 | 0 | 0 | 12.3 s | n/a |

E2E scenarios (PRD 12.1): ✅ assignment from creation to released grade; ✅ real-time chat with typing indicator and read ticks; ✅ offline messages and submission sync when back online; ✅ student cannot open a course they are not enrolled in; ✅ MOB-2 the app is installable and opens with no network; ✅ MOB-1 / MOB-3 phone layout: bottom tabs, 44 px touch targets, camera capture.

## Security checks

- `composer audit`: no advisories
- `npm audit`: 0 vulnerabilities
- OWASP ZAP baseline: [`results/zap-baseline.md`](results/zap-baseline.md) — High 0, Medium 1, Low 0, Informational 4
- Manual OWASP Top 10 review: [`security-checklist.md`](security-checklist.md)

## Non-functional requirements

| ID | Requirement | Target | Measured | Result |
|---|---|---|---|---|
| NFR-1 | REST latency, 100 VUs, mixed reads | p95 < 300 ms, errors < 1 % | p95 12.6 ms, errors 0.00 % (6944 requests, 86 req/s) | ✅ pass |
| NFR-2 | Send message REST call, 50 senders | p95 < 400 ms | p95 12.4 ms (2005 messages, errors 0.00 %) | ✅ pass |
| NFR-3 | End-to-end message latency with 200 open sockets | p95 < 500 ms | p95 23 ms, median 10 ms (16200 deliveries of 81 messages) | ✅ pass |
| NFR-4 | Concurrent WebSockets across 2 Reverb instances | ≥ 500 stable for 2 min | 500 connected, 0 unexpected closes, 0 errors, held 120 s; peak reported by Reverb 500 (127.0.0.1:8201: 250, 127.0.0.1:8202: 250) | ✅ pass |
| NFR-5 | Availability: one API replica stopped during load | errors < 2 %, recovers within 15 s | errors 0.00 % (0 of 7031), 0 failed after the stop, recovered in 0.0 s (stopped 127.0.0.1:8002) | ✅ pass |
| NFR-6 | Sync after 50 offline messages (with retries) | all delivered, no duplicates, order kept | 50/50 delivered once each, in order (checks passed 100 %) | ✅ pass |
| NFR-7 | Scaling API 2 → 3 replicas | throughput increase recorded | 1911 → 2059 req/s (+7.7 %), p95 87.0 → 131.7 ms, errors 0.00 % / 0.00 % | ✅ recorded |
| NFR-8 | Lighthouse (mobile) on the PWA | installable; performance ≥ 80 | performance 88, accessibility 100, best practices 96, SEO 91; Chrome: installable, opens offline | ✅ pass |

## Traceability (P0 requirements → automated tests)

Test names carry the requirement ids (PRD §7); this table is built from the JUnit files above.

| Requirement | Tests | Result | Other evidence |
|---|---:|---|---|
| AUTH-1 | 7 | ✅ all pass |  |
| AUTH-2 | 2 | ✅ all pass |  |
| AUTH-4 | 2 | ✅ all pass |  |
| AUTH-6 | 7 | ✅ all pass |  |
| LMS-1 | 3 | ✅ all pass |  |
| LMS-2 | 0 | ✅ see evidence | course page tabs used by every E2E scenario |
| LMS-3 | 1 | ✅ all pass |  |
| LMS-4 | 2 | ✅ all pass |  |
| LMS-5 | 2 | ✅ all pass |  |
| LMS-6 | 6 | ✅ all pass |  |
| LMS-7 | 3 | ✅ all pass |  |
| LMS-8 | 4 | ✅ all pass |  |
| LMS-9 | 8 | ✅ all pass |  |
| LMS-10 | 8 | ✅ all pass |  |
| MSG-1 | 1 | ✅ all pass |  |
| MSG-2 | 2 | ✅ all pass |  |
| MSG-3 | 1 | ✅ all pass |  |
| MSG-4 | 2 | ✅ all pass |  |
| MSG-5 | 2 | ✅ all pass |  |
| MSG-6 | 1 | ✅ all pass |  |
| MSG-7 | 9 | ✅ all pass |  |
| MSG-8 | 1 | ✅ all pass | E2E-2 (typing indicator between two browsers) |
| MSG-9 | 1 | ✅ all pass | E2E-2 (online presence) |
| NOT-1 | 6 | ✅ all pass |  |
| NOT-2 | 2 | ✅ all pass |  |
| NOT-3 | 4 | ✅ all pass |  |
| NOT-4 | 1 | ✅ all pass |  |
| MOB-1 | 2 | ✅ all pass |  |
| MOB-2 | 1 | ✅ all pass |  |
| MOB-3 | 1 | ✅ all pass |  |
| MOB-4 | 1 | ✅ all pass | E2E-3 (offline browsing from IndexedDB) |
| MOB-5 | 4 | ✅ all pass |  |
| MOB-6 | 1 | ✅ all pass | E2E-3 (status pill, sync on reconnect) |
| MOB-7 | 2 | ✅ all pass |  |
| DIST-1 | 1 | ✅ all pass |  |
| DIST-2 | 1 | ✅ all pass | NFR-5 failover load test |
| DIST-3 | 0 | ✅ see evidence | NFR-3/4 k6 ws-latency across 2 Reverb instances; `make verify-realtime` |
| DIST-4 | 0 | ✅ see evidence | System Status replica check (measured lag) on MySQL |
| DIST-5 | 1 | ✅ all pass | NFR-7 scaling test (instances discovered by Traefik) |
| DIST-6 | 2 | ✅ all pass |  |
| SEC-1 | 1 | ✅ all pass |  |
| SEC-2 | 10 | ✅ all pass |  |
| SEC-3 | 5 | ✅ all pass |  |
| SEC-4 | 3 | ✅ all pass |  |
| SEC-5 | 5 | ✅ all pass |  |
| SEC-6 | 2 | ✅ all pass |  |
| SEC-7 | 3 | ✅ all pass |  |
| SEC-8 | 3 | ✅ all pass |  |
| SEC-9 | 3 | ✅ all pass |  |

## Notes

- All services ran natively on one machine (no Docker): load generator, gateway, API replicas, Reverb, MySQL primary and replica, Redis and MinIO share the CPU, so absolute numbers are a lower bound for a multi-machine deployment.
- Load-test users come from `LoadTestSeeder` (500 students, 10 courses + one 260-student course chat, 1 000 messages). Tokens are issued with `php artisan loadtest:tokens` so the login rate limits are not part of the measurement; per-user API limits stay active.
- NFR-3 latency is measured from the sender stamping the time in the message body to the `MessageSent` frame arriving at each of the 200 receivers (same clock: k6 runs on the host), through REST → MySQL → Horizon broadcast queue → Reverb (Redis pub/sub across instances) → Traefik.
- NFR-5 uses Traefik's retry middleware and registry de-registration on SIGTERM (decisions D8, D7); the recovery time is the time from `pm2 stop` to the last failed request.
- NFR-7 runs a closed-model load without think time (96 VUs, 40 s) at 2 and 3 replicas; requests rotate over 500 users with load-test tokens exempt from per-user rate limits (D60).
- NFR-8: Lighthouse 12 no longer has a PWA category, so installability comes from Chrome's `Page.getInstallabilityErrors` (empty = installable) plus an offline reload of the app shell.
