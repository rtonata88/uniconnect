# Design decisions

Short records of decisions taken where the PRD was open or where the environment forced a choice.
Each one lists the choice, the reason, and the trade-off. Numbered in the order they were made.

## Phase 1 — infrastructure

### D1. Every host/port comes from `backend/.env`
`backend/.env` is the single source of truth for ports and hosts. `ecosystem.config.cjs`, the
`Makefile`, the shell scripts (`scripts/lib/env.sh`) and the Traefik static config all read it.
The defaults in `.env.example` match the PRD (80/443, 8090 dashboard, 3306/3307, 5173, 8001+, 8101+).
**Why:** a developer machine already runs other software; on the build machine port 80 (Herd nginx),
3306 (MariaDB), 5173, 8090 and 8103 were taken. The local `.env` overrides them
(8080, 3316/3317, 5174, 8091, Reverb from 8201). HTTPS stays on 443, so `https://localhost` works.
`scripts/check-prereqs.sh` warns about busy ports.

### D2. MySQL primary and replica both run under PM2 with project-local datadirs
The PRD runs the primary as an OS service and the replica as a second `mysqld`. Here both run as
PM2 apps (`mysql-primary`, `mysql-replica`) with `--defaults-file=storage/mysql/*.cnf` and
datadirs in `storage/mysql/`. `infra/mysql/setup-replication.sh` initialises them, creates the
replication user and app user, and starts GTID replication (`SOURCE_AUTO_POSITION=1`).
**Why:** it works the same way on macOS and Ubuntu, coexists with any MySQL or MariaDB already
installed (the build machine runs MariaDB on 3306 with the Homebrew datadir), and `make up/down` controls
the whole system. `--defaults-file` keeps other `my.cnf` files out. **Trade-off:** `make down` also
stops the database. Root has no password and is reachable only through each instance's Unix socket.

### D3. Octane's FrankenPHP binary embeds PHP 8.5
`php artisan octane:install --server=frankenphp` downloads the static FrankenPHP 1.13 build, which
embeds PHP 8.5.12. The CLI processes (Reverb, Horizon, scheduler, registry, tests) run PHP 8.4. The
code targets PHP 8.4 and runs unchanged on 8.5. `PHP_BIN` selects the CLI binary (`php84` under
Laravel Herd).

### D4. Redis 7+ (8.x accepted)
The build machine has Redis 8.6 from Homebrew. Redis 8 is fully compatible with every feature used
(keys with TTL, pub/sub, keyspace notifications), so the check accepts any version ≥ 7.

### D5. Traefik static config is rendered from `.env`
Traefik's static configuration cannot read a `.env` file. `scripts/render-traefik.cjs` writes
`infra/traefik/traefik.yml` (git-ignored) each time `ecosystem.config.cjs` is loaded. The dynamic
file-provider config (`infra/traefik/dynamic/routes.yml`) is committed and uses Traefik's own
`{{ env "..." }}` templating. PM2 passes those variables to the Traefik process.

### D6. Traefik Redis KV layout and keyspace notifications
Checked against the Traefik v3 KV reference: servers go under
`traefik/http/services/{svc}/loadBalancer/servers/{i}/url` and health checks under
`.../loadBalancer/healthCheck/{path,interval,timeout}`. Keys are case-insensitive. Routers stay in
the file provider and point at `api@redis` and `reverb@redis`. The Redis provider **needs keyspace
notifications** to see changes, so `registry:sync` runs `CONFIG SET notify-keyspace-events KEA` when
they are off. `registry:sync` writes only the keys that changed (so Traefik reloads only when membership
changes) and adds new servers before deleting stale indexes, so the list is never empty during an update.
The registry and Traefik keys live in Redis DB `REDIS_REGISTRY_DB` (0) without the Laravel prefix.

### D7. How instances heartbeat
- `api`: Octane cannot easily run a timer, so `scripts/run-api.sh` starts a sidecar,
  `php artisan registry:heartbeat api --port --pid --health`. The sidecar advertises the instance
  only while `/api/health` returns 2xx. It stops when Octane exits and deletes the key at once on SIGTERM.
- `reverb`: registers from inside its own ReactPHP loop (`App\Support\Registry\ReverbHeartbeat`,
  hooked on `CommandStarting`). It also reports its live connection count (used by the System
  Status page) and logs which instance each socket subscribed on (DIST-3).
- `worker` and `scheduler`: `scripts/run-with-heartbeat.sh` runs the same sidecar without a URL.
  Their key uses the PID in place of the port: `registry:worker:{host}:{pid}`.
- PM2 stops these apps with SIGTERM (`kill_signal`), so keys disappear immediately.

### D8. Failover uses Traefik's `retry` middleware
Between an instance dying and the next sync (≤ 2 s plus Traefik's 1 s throttle), requests that
reach the dead server would fail with 502. The `api` and `reverb` routers use a `retry`
middleware (3 attempts). Traefik retries only when the backend connection failed, before any response,
so POSTs are never duplicated. Measured: `make kill-api` during a request loop gave 12/12 × 200
(without retry: about 50% 502 for 2 s).

### D9. API → Reverb publishing goes through an internal gateway entrypoint
Laravel's Reverb broadcaster posts events to Reverb's HTTP API. Instead of pinning one Reverb
instance, it posts to Traefik's plain-HTTP `internal` entrypoint (`GATEWAY_INTERNAL_PORT`, bound to
`GATEWAY_INTERNAL_BIND`), which balances over the discovered `reverb@redis` servers. Redis
pub/sub scaling then fans the event out to every instance. Browsers connect through the public
HTTPS gateway (`/app/{key}`), so no client configuration is needed.

### D10. MinIO is routed through the gateway on the bucket path
`/uc-private/*` on the gateway proxies to MinIO with the original Host header. Presigned URLs are
signed for the host the browser used, so they are same-origin HTTPS links (no mixed content, works
from a phone on the LAN), and the bucket stays private because every object needs a valid signature.

### D11. MinIO built from source on Apple M5
MinIO has stopped publishing binaries (`dl.min.io` returns 410). The Homebrew bottle and a plain
source build crash at start-up on Apple M5 CPUs inside `github.com/shoenig/go-m1cpu` v0.1.6.
`scripts/build-minio-from-source.sh` builds `minio` and `mc` with that dependency upgraded to v0.2.4.
`MINIO_BIN_DIR` points at the result. Other machines can keep the packaged binaries.

### D12. `make down` deletes only UniConnect's processes
`pm2 delete ecosystem.config.cjs` is used instead of `pm2 delete all`, so other PM2 apps on the machine survive.

### D13. Tests use SQLite in-memory and Redis DB 15
Pest runs against an in-memory SQLite database (fast and isolated). Registry tests use the real local
Redis on DB 15, which they flush. MySQL-only behaviour, such as replication status, is behind small
services that are faked in tests.

### D14. shadcn/ui setup
The current shadcn CLI (v4) was used with the `radix-nova` style. Its registry replaced the old
`form` component with `field`, so forms use `Field` + react-hook-form + zod. `next-themes` was replaced
by a small Zustand theme store, because its inline anti-flash script would be blocked by the CSP
(`script-src 'self'`).

### D15. Stateless API
`SESSION_DRIVER=array`: the API is token-only (Sanctum bearer tokens), so there is no session state to share between replicas.
