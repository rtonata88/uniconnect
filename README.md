# UniConnect

Distributed learning and communication platform: an LMS (courses, materials, assignments,
quizzes, gradebook), WhatsApp-style messaging and real-time notifications. It ships as an
installable, offline-capable PWA. Master's project, IUM — Distributed and Mobile Systems.

Every service runs **natively** under PM2. No Docker.

> Work in progress: built phase by phase following `PRD.md` §14. See `docs/decisions.md`.

## Quick start

```bash
./scripts/install-deps-macos.sh      # or scripts/install-deps-ubuntu.sh
mkcert -install                      # trust the local CA once
make setup                           # prerequisites check, packages, keys, certs, replication, bucket, migrations
make up                              # pm2 start ecosystem.config.cjs
```

Open `https://localhost` (or the `APP_URL` in `backend/.env`).

| URL | What |
|---|---|
| `https://localhost` | Web app / PWA |
| `https://localhost/api/health` | API health (`X-Served-By` header) |
| `http://localhost:8090` | Traefik dashboard (`GATEWAY_DASHBOARD_PORT`) |
| `https://localhost/horizon` | Horizon (queues) |
| `http://localhost:9001` | MinIO console |

## Commands

| Command | |
|---|---|
| `make up` / `make down` | start / stop every process |
| `make status` | `pm2 ls` + System Status |
| `make scale API=3 REVERB=3` | scale API / Reverb instances (discovered automatically) |
| `make kill-api` | stop one API instance (failover demo) |
| `make seed` | demo data |
| `make test` / `make loadtest` | automated tests / k6 load tests |
