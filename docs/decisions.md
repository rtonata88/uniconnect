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

### D16. Scribe generates static docs only
Scribe writes static HTML to `docs/api` (`type: static`). The app has no `/docs` route and the
gateway does not expose one. Response calls are disabled and example models are only
`factoryMake`. **Why:** a live docs route with response calls can leak real data from the
database (raised by an automated security review in phase 1).

## Phase 2 — auth and users

### D17. Login throttling counts failed attempts only
AUTH-1 limits *failed* attempts (5 per minute per email + IP) through `RateLimiter::hit`, cleared on
success, so a user who signs in correctly is never throttled. Other unauthenticated endpoints
(forgot/reset password) share a `guest` limiter (10 per minute per IP).

### D18. Avatars use the same signed-URL path as files, valid 60 minutes
`avatar_path` points into the private bucket. `UserResource` embeds a presigned URL valid for 60 minutes
instead of the 5 minutes used for attachments: avatars appear in lists that stay on screen and in the
offline cache, and they are low-sensitivity. Expired URLs fall back to initials.

### D19. Email and student number are visible only to self, lecturers and admins
`UserResource` hides `email` and `student_number` when a student looks at another user (chat
lists, course people).

### D20. Admins cannot demote or deactivate themselves
This prevents locking every administrator out. Enforced by `UserPolicy::changeAccess`.

### D21. TanStack Table pinned to v8
`@tanstack/react-table@latest` is now v9, which has a different API (`createCoreRowModel`, table
features). shadcn's data-table pattern targets v8, so the project pins `^8`.

### D22. Login brute-force protection beyond the PRD minimum
Besides AUTH-1's 5 failures per minute per email + IP, logins are limited to 20 failures per hour
per account (any IP) and 30 attempts per minute per IP (any email). `X-Forwarded-For` is trusted only
from `TRUSTED_PROXIES` (default loopback), so clients cannot rotate their apparent IP. Raised by the
automated security review of phase 2. Consequence: k6 scripts pre-issue tokens instead of logging in
under load.

## Phase 3 — courses and LMS core

### D23. Who may do what in a course
Admins can *view* every course and manage course records and enrolments. Only users enrolled as
lecturer in that course can *teach* it: upload materials, post announcements, create assignments
and grade. An admin who needs to teach is enrolled as lecturer, which requires the lecturer role.
Students must be enrolled as student to submit.

### D24. One submission row per student and assignment
Resubmitting before the deadline updates the same row (`attempt` + 1). `client_uuid` holds the
idempotency key of the latest submit, so replays from the outbox return the stored row with 200.
A resubmission clears any earlier grade. The late penalty is stored on the assignment and applied
when the final score is computed (raw score kept).

### D25. Publishing
Materials, announcements and assignments are published immediately or saved as drafts
(`published_at` null). The publish events (`MaterialPublished`, `AnnouncementPosted`,
`AssignmentPublished`) fire when an item becomes visible, which is when notifications go out (phase 6).
Scheduled publication was left out as unnecessary for the acceptance criteria.

### D26. Upload type detection uses libmagic
The MIME type is detected from the file bytes (`finfo`), never from the client's Content-Type or the
extension, and must match the allow-list entry for the extension. Office files detected as
`application/zip` are stored with their declared Office type. Original names are sanitised.

### D27. Downloads: 302 for browsers, JSON for the app
`GET /api/attachments/{id}` returns 302 to a 5-minute presigned URL as the PRD specifies. Browser
`<img>`/`<a>` elements cannot send the bearer token, so the app calls `?json=1` and uses the returned
URL. Traefik strips the `Authorization` header on the bucket route, because MinIO rejects a request
that carries both a bearer token and a signature. A same-origin XHR redirect would otherwise forward
the token.

### D28. The per-account login limit is keyed on email + network (/24, /48)
The automated review pointed out that a per-account lock across *all* IPs lets an attacker lock the real
user out. The account limiter is therefore keyed on the email plus the client's /24 (IPv4) or /48
(IPv6) network: rotating addresses inside one network is still blocked, while the owner signing in
from another network is unaffected. The per-IP limiter (30/min) still caps every address.

### D29. A graded submission is final
Resubmitting after the lecturer graded returns 409, so a grade (and the audit trail behind it) is
never silently wiped (raised by the automated review). The lecturer can still change the grade.

## Phase 4 — quizzes and gradebook

### D30. Quiz timing is enforced by the server
Starting an attempt stores `deadline_at = min(start + time limit, closes_at)` and returns the
server time, so the client countdown is independent of the device clock (tested). Autosaves and the
final submit are accepted up to 15 s after the deadline (network grace). Later answers are rejected
with 409. `php artisan quizzes:close-expired` (scheduler, every minute) auto-submits attempts whose
client went away, using only the answers saved in time.

### D31. Quiz grading and visibility
MCQ and true/false questions are auto-graded. Short answers with an expected answer are compared
case-insensitively after trimming and collapsing spaces; without one they wait for manual grading.
The gradebook keeps the student's best submitted attempt. In line with LMS-10, students see quiz
scores and correct answers only after the lecturer releases that grade column. Questions are locked
once any student has started.

### D32. Gradebook weighting
Each assignment and quiz owns a grade item (kept in sync through model events). Lecturers can add
manual items. Weighted total = Σ(percentage × weight) / Σ(weights of graded items), so ungraded
items do not drag a student down mid-semester. With no weights set, graded items count equally.
Assignment grades use the final score after the late penalty. CSV export neutralises spreadsheet
formula prefixes.

## Phase 5 — messaging

### D33. Receipts: rows for exact ticks, watermarks for unread counts
`message_receipts` rows are written only when a recipient reports a message as delivered or read
(no pre-created rows for every recipient), with insert-or-ignore so repeated reports are cheap. Tick
status for the sender is exact: *delivered* when every participant who was in the chat when the
message was sent has a receipt, *read* when all of them have `read_at`. "Message info" lists the same
rows. Per-participant `last_read_message_id` / `last_delivered_message_id` watermarks give unread
counts with one sub-query. A `ReceiptsUpdated` event goes to the sender only when a message's status
or read count actually changed.

### D34. Clients subscribe to every conversation's presence channel
The PRD puts `MessageSent` on `presence-conversation.{id}`, so a client joins the presence channel of
every conversation it has, not only the open one. That is how it receives (and acknowledges) messages
for chats it is not looking at, which WhatsApp-style "delivered" ticks need. Course chats are small at
this scale. Conversation membership changes arrive on `private-user.{id}` as
`ConversationCreated`/`ConversationUpdated` with a minimal payload, and the client re-fetches what it
is allowed to see.

### D35. Broadcasts have their own Horizon queue
All broadcast events use the `broadcasts` queue, served by a dedicated Horizon supervisor (2–4
processes), and the Redis queue uses blocking pops (`block_for`). Measured end to end: REST send →
socket frame on another Reverb instance in 20–30 ms, UI to UI about 80 ms. With the default polling
(sleep 3 s) it was 1–2 s. Several broadcast workers can deliver two quick messages out of order, so
the client always orders by server id (PRD §6).

### D36. Chat UI reads from IndexedDB
The chat list and message list render from Dexie (`useLiveQuery`). API responses and socket events
are written into Dexie. A sent message is stored locally first (`client_uuid` key, clock icon) and
delivered by the outbox (PRD 5.3, 9.2), so the same code path works online and offline. Receipts are
batched into a single outbox entry. Pending messages sort after server messages in creation order.

### D37. Direct chats need a shared course; contacts endpoint
MSG-2 is enforced on the server (`assertCanChat`). Admins can chat with anyone, and anyone can message
an admin. `GET /api/contacts` (not in the PRD's API list) returns the people a user may start a chat
with, for the "New chat" dialog. `GET /api/config` (also added) hands the web app the Reverb key and
VAPID public key at runtime.

### D38. Deleting a message clears its content
"Delete for everyone" (sender, within 1 hour) soft-deletes the row and wipes the encrypted body, so
the content is gone from the database, not just hidden. Clients keep the row and show "This message
was deleted".
