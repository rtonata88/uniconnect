# UniConnect

**Distributed learning and communication platform** — Master's project, IUM, *Distributed and Mobile Systems*.

UniConnect puts a learning management system and WhatsApp-style messaging into one app for lecturers
and students. It runs as an installable, offline-capable Progressive Web App on phones and computers,
on a horizontally scalable back end of **native processes managed by PM2 — no Docker anywhere**.

> Screenshots: _add `docs/screenshots/*.png` here (dashboard, course page, chat on a phone, System Status)._

## Features

| Area | What you get |
|---|---|
| **LMS** | Courses with Overview, Materials (by week, available offline), Assignments (late policy, offline submission, grading), Quizzes (MCQ / true-false / short answer, timer, auto-grading, manual marking), Gradebook (weights, release, CSV), Announcements, Chat, People |
| **Messaging** | Private, group and per-course chats · real-time delivery over WebSockets · files and photos · **clock → ✓ → ✓✓ → blue ✓✓** ticks with *Message info* · typing indicator · online / last seen · reply · delete for everyone · mute · infinite history |
| **Notifications** | Bell with live count, toasts, Web Push to browsers and installed PWAs (desktop + phone), deadline reminders 24 h / 1 h, per-category preferences |
| **Mobile & offline** | Installable PWA (manifest, maskable icons, install button, iOS steps), app shell works with no network, IndexedDB replica, outbox for messages/submissions/announcements, delta sync, status pill, push-click deep links, camera capture, pull-to-refresh, Web Share |
| **Distributed** | Traefik gateway with **service discovery from a Redis registry**, 2+ API replicas (Octane/FrankenPHP), 2+ Reverb replicas with Redis pub/sub, MySQL **primary + GTID replica** with read/write split, Horizon workers, MinIO object storage, `X-Served-By`, live **System Status**, scale and fail over with one command, optional second machine |
| **Security** | Sanctum tokens, policies on every model, channel authorization, encrypted message bodies, private files behind 5-minute signed URLs, upload allow-list with content sniffing, rate limits, audit log, CSP/HSTS, OWASP review + ZAP scan |

## Quick start

```bash
./scripts/install-deps-macos.sh        # or ./scripts/install-deps-ubuntu.sh
mkcert -install                        # trust the local HTTPS certificate authority (once)
make setup && make up && make seed
```

Open **https://localhost**. Details, port changes, phones and multi-machine: [docs/deployment.md](docs/deployment.md).

### Demo accounts

All passwords: `Password123!`

| Role | E-mail |
|---|---|
| Admin | `admin@uniconnect.test` |
| Lecturer | `mziezo@uniconnect.test` (DMS901, DB903), `jshikongo@uniconnect.test` (SE902) |
| Students | `student1@uniconnect.test` … `student10@uniconnect.test` |

Seeded courses: **DMS901** Distributed and Mobile Systems, **SE902** Advanced Software Engineering,
**DB903** Advanced Databases — each with weekly PDF notes, announcements, two assignments (one due in
two days, one past due), a quiz and a course chat with history; plus direct and group chats.

### URLs

| URL | |
|---|---|
| `https://localhost` | the app (PWA) |
| `https://localhost/api/health` | health check — shows `X-Served-By` |
| `http://localhost:8090/dashboard/` | Traefik dashboard (services discovered from Redis) |
| `https://localhost/horizon` | Horizon — queues and jobs (host machine only) |
| `http://localhost:9001` | MinIO console |
| `docs/api/index.html` | REST API reference (Scribe) |

(Ports are the defaults; they come from `backend/.env`.)

## Commands

| Command | |
|---|---|
| `make setup` | prerequisites check, packages, keys, certificates, MySQL replication, bucket, migrations, build |
| `make up` / `make down` / `make restart` | start / stop / restart every process (PM2) |
| `make status` | `pm2 ls`, health and the System Status summary |
| `make seed` | fresh demo data |
| `make scale API=3 REVERB=3` | scale replicas — new instances are discovered automatically |
| `make kill-api` | stop one API instance (failover demo) |
| `make verify-realtime` | two users on different Reverb instances chat in real time |
| `make test` | Pest + Vitest + Playwright → `docs/testing/results/` + report |
| `make loadtest` | k6 NFR-1…7, failover, scaling, Lighthouse → results + report |
| `make zap` | OWASP ZAP baseline scan |
| `make docs` | regenerate the API reference |

## Project layout

```
backend/      Laravel 13 API, Reverb, Horizon, scheduler, service registry (PHP 8.4)
frontend/     React 19 + TypeScript + Vite + shadcn/ui PWA (Dexie, TanStack Query, Echo)
infra/        traefik/ (static + dynamic config, certs) · mysql/ (primary, replica, setup) · minio/
scripts/      install / check prerequisites, run-api.sh, run-reverb.sh, status, certificates
loadtests/    k6 scripts (REST, messages, WebSockets, sync), failover.sh, scale.sh, Lighthouse
docs/         architecture, API, realtime, deployment, user guide, testing, decisions, demo script
ecosystem.config.cjs   PM2 definition of every process
Makefile
```

## Documentation

| Document | |
|---|---|
| [docs/architecture.md](docs/architecture.md) | deployment, components, sequences, ER and use-case diagrams; service discovery, replication, Reverb scaling, sync |
| [docs/api/](docs/api/index.html) | REST API reference (generated by Scribe, OpenAPI in `docs/api/openapi.yaml`) |
| [docs/realtime.md](docs/realtime.md) | WebSocket channels, events and payloads |
| [docs/deployment.md](docs/deployment.md) | native install (Ubuntu, macOS), configuration, HTTPS, replication, scaling, multi-machine, phones |
| [docs/user-guide.md](docs/user-guide.md) | how to use UniConnect as a student, lecturer or admin |
| [docs/testing/](docs/testing/) | [test plan](docs/testing/test-plan.md), [security checklist](docs/testing/security-checklist.md), [**TEST_REPORT.md**](docs/testing/TEST_REPORT.md), raw results |
| [docs/decisions.md](docs/decisions.md) | design decisions and trade-offs |
| [docs/demo-script.md](docs/demo-script.md) | the 10-minute presentation demo |

## Technology

React 19, TypeScript, Vite 8, Tailwind CSS 4, shadcn/ui, TanStack Query/Table, Zustand, Dexie, Workbox
(vite-plugin-pwa), Laravel Echo + pusher-js · Laravel 13 (PHP 8.4), Octane + FrankenPHP, Sanctum, Reverb,
Horizon, Scribe, laravel-notification-channels/webpush · MySQL 8.4 LTS (GTID replication), Redis, MinIO,
Traefik v3 (Redis KV + file providers), PM2 · Pest, Vitest, Playwright, k6, Lighthouse, OWASP ZAP.
