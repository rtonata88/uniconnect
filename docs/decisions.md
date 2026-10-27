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

### D39. Removing a chat member rotates the conversation channel
Channel names are versioned: `presence-conversation.{id}.{version}`. When a member is removed or
leaves (including un-enrolment from a course), `channel_version` is incremented. Remaining members get
`ConversationUpdated`, re-fetch the conversation (whose `channel` field carries the new name) and
re-subscribe. The removed user's existing socket subscription stops receiving events, and the
authoriser rejects both their new subscription and any stale version. The plain
`presence-conversation.{id}` name in the PRD gained the version suffix for this reason (raised by the
automated review).

### D40. `presence-online` shares only ids
The PRD's global presence channel lets every signed-in user see who is online. Member info is reduced to
`{id}` (no names) to limit what can be enumerated. The channel stays because online dots and NOT-3's
"not active in the app" check depend on it.

### D41. Known networks skip login slow mode
Slow mode (D28/D22) uses one slot per account, which an attacker could keep busy. Networks the account
has signed in from successfully in the last 90 days bypass slow mode (they are still limited by
the per-network limiter), so the owner can still get in while unknown networks share the slow slot.

## Phase 6 — notifications

### D42. Notification channels
Every notification is stored (`database`) and pushed live through a small custom channel that
dispatches a `NotificationCreated` broadcast on `private-user.{id}`. Laravel's built-in broadcast
channel would name the event `BroadcastNotificationCreated`; the PRD names it `NotificationCreated`.
Web Push is added only when the category is enabled (NOT-5) and the user is not active in the app,
meaning not a member of `presence-online` on any Reverb instance (Reverb HTTP API, cached 5 s).
If presence is unknown, the push is sent rather than lost. Chat messages are push-only (they have their own
unread counts) and skip muted conversations (MSG-12). Fan-out runs on the `push` queue so a
course-wide announcement never slows the request.

### D43. The service worker decides about in-focus pushes
A push that arrives while an app window is focused and visible is not shown as a system
notification (the in-app toast already told the user). Notification clicks focus an existing window
and ask it to navigate to the target screen (`NAVIGATE` message), or open a new window (MOB-7).
On logout the device's push subscription is removed while the token is still valid, so the next
user of a shared device does not receive the previous user's pushes.

### D44. Deadline reminders
`assignments:remind` runs every five minutes (scheduler, `onOneServer`). For each published
assignment entering the 24 h or 1 h window, it atomically claims the window (`reminded_*_at`) and notifies
enrolled students without a submission. A deadline change clears both markers, so the reminders are sent again.

### D45. Web Push endpoints are allow-listed (SSRF)
The server sends HTTP requests to the endpoint inside a browser's push subscription, so a malicious
client could point it at an internal address. Endpoints must be HTTPS on a known push service
(FCM, Mozilla autopush, Windows WNS, Apple; configurable via `PUSH_ALLOWED_HOSTS`), with no
port or credentials in the URL, and the push HTTP client does not follow redirects. Raised by the automated review.

## Phase 7 — offline and PWA

### D46. Sync cursor and overlap
Timestamps stay at second precision. Microsecond columns were tried, but MySQL rounds fractional
seconds in other datetime columns and Laravel binds dates without them, which made freshly published
items briefly invisible. The cursor is `updated_at|id` (PRD 9.3). Each round re-reads a 2-second
overlap so a row committed late by a concurrent transaction is never missed, and clients upsert by
id, so the overlap causes no duplicates. Messages are keyset-paginated by `(updated_at, id)`, 500 per
page; continuation cursors (`…|more`) carry no overlap, so the loop always advances (tested with 1,100
messages sharing one timestamp). A first sync sends the newest 50 messages per conversation; older history
comes from the paginated messages endpoint on scroll. `memberships` (course and conversation ids)
lets clients drop data the user lost access to. Drafts and deleted items appear in `deleted`.

### D47. Offline read path and writes
Screens read through a repository: online it fetches and stores in IndexedDB, offline (or when a
request fails without a response) it answers from IndexedDB and the banner says "Offline – showing
saved data". TanStack Query runs with `networkMode: 'always'` so queries are not paused offline.
Submissions and announcement drafts are sent directly while online (so validation errors appear
at once) and through the outbox otherwise. A queued submission keeps its file blob in IndexedDB and
shows as "Waiting to send". Lecturer-only views (gradebook, submissions table) need a connection.

### D48. What the service worker caches
The service worker precaches the app shell (MOB-2) and caches private images by path (presigned query
strings change on every request), so chats show their photos offline. API data is never cached by
the service worker: it lives in IndexedDB. On logout every local table and the image cache are wiped.
Materials marked "available offline" are stored as blobs in IndexedDB and opened from there,
both offline and online.

### D49. Outbox lock bug
`processOutbox()` called while offline finished synchronously, so its internal `finally { running = null }`
ran before the outer assignment and left a resolved promise as a permanent lock. Queued writes
then never left the device after reconnecting. Found in the browser end-to-end test. Fixed by
clearing the lock after assignment (same pattern in the sync engine), and covered by a regression test.
`localStorage.setItem('uc-debug','1')` exposes the sync internals as `window.__uc` for troubleshooting.

## Phase 8 — distributed demo features

### D50. Replication lag is measured, not just reported
`Seconds_Behind_Source` is an integer and usually reads 0 on a LAN. The System Status page also
measures the real delay: the API writes a random token to `replication_probes` on the primary and
polls the replica (20 ms steps, up to 2 s) until it appears. Typical value on the build machine: 20–30
ms. Monitoring uses a dedicated `mysql_replica` connection. The application's `mysql` connection is
sticky (read-your-writes), so after any write in the same request (e.g. `last_seen_at`) its "read"
PDO is the primary, which made the replica look stopped.

### D51. System Status sources
Instances come straight from the Redis registry (status *healthy* when the last heartbeat is under
2 intervals old, *stale* until the TTL, then the key is gone). The gateway section shows both the
server lists `registry:sync` wrote to Redis KV and Traefik's own health-checked view from its API on
the dashboard port. WebSocket connections are the sum of the per-instance counts Reverb reports in
its heartbeat. Queue sizes are the Redis list lengths of `broadcasts`, `default` and `push`.

### D52. Dashboard endpoint
`GET /api/dashboard` (not in the PRD's API list) returns the role-aware summary for PRD 10.1 /
LMS-11 in one request instead of N per-course calls. The client keeps the last response in
IndexedDB so the dashboard still renders offline.

### D53. Verifying cross-instance chat (DIST-3)
`make verify-realtime` connects two users through the gateway until their sockets land on different
Reverb instances (each instance logs `socket <id> subscribed to <channel>`), then sends five messages
over REST and reports delivery latency. Measured on the build machine: 5/5 delivered, 12–37 ms.

## Phase 9 — security hardening

### D54. Token in IndexedDB (SEC-11) and XSS defence in depth
The PRD stores the bearer token in IndexedDB so the service worker and the app share it. Any script
running in the page could read it, so the defence is to prevent script injection: the web CSP allows
no inline or third-party script (`script-src 'self'`, `object-src 'none'`), all user content is
rendered as text by React (no `dangerouslySetInnerHTML` anywhere), uploads can never be HTML/SVG/JS,
and files are served by MinIO with `nosniff` and an attachment or inline disposition from the presigned URL.

### D55. Admin consoles stay on the host
Horizon's dashboard (job payloads), Traefik's dashboard/API and the MinIO console have no
authentication suitable for the LAN. Horizon is served only to `HORIZON_ALLOWED_IPS` (default:
the host itself, using the real client IP from the trusted gateway). Traefik's dashboard
(`GATEWAY_DASHBOARD_BIND`) and the MinIO console (`MINIO_CONSOLE_BIND`) bind to 127.0.0.1 by
default. The MinIO S3 port stays reachable because API instances on other machines need it.

### D56. Debug off, uniform errors, API headers
`APP_DEBUG=false` by default, so error responses only contain `{message, errors?}`. The API adds its own
security headers (`nosniff`, frame options, referrer policy, `default-src 'none'`, `no-store`) so
they are present even when an instance is reached directly in a multi-machine setup. CORS allows only
`APP_URL` plus `CORS_ALLOWED_ORIGINS`.

### D57. `compression` override
`npm audit` flagged `compression` < 1.8.2 (denial of service), pulled in by `serve`, the static server of
the `web` process. Even the newest `serve` pins 1.8.1, so `package.json` overrides it to `^1.8.2`.
Result: 0 vulnerabilities. `composer audit`: no advisories.

## Phase 10 — testing and performance

### D58. Tests can never touch a real database
A test runner that exported all of `backend/.env` into the environment overrode PHPUnit's
SQLite settings (real environment variables win over `phpunit.xml` `<env>` in Laravel's lookup), and
`RefreshDatabase` wiped the development database. Two fixes: `scripts/test-backend.sh` reads only
the two values it needs, and `tests/TestCase.php` refuses to run against anything other than SQLite or
a `*_testing` database. The demo data was restored with `make seed`.

### D59. Coverage driver on demand
Coverage needs Xdebug or PCOV. Instead of changing the global php.ini, `XDEBUG_EXTENSION` in
`backend/.env` (Herd ships `xdebug-84-arm64.so`) is loaded only for `make test-backend`. Without a
driver, the tests still run and the report says "not measured".

### D60. Load-test identities
k6 uses tokens issued by `php artisan loadtest:tokens` for the 500 `lt_*` users. Logging in under load
would measure the login throttling (by design 30/min per IP) instead of the system. The tokens carry an
extra `loadtest` ability that exempts them from the per-user `api` and `messages` limiters. A first NFR-7
run without this got 20–36 % HTTP 429s (≈ 1 900 req/s over 500 users ≈ 230 req/min each), which made the
throughput figure meaningless, so it was discarded. The ability can only be minted on the server by the
artisan command. The check looks for the ability explicitly, because Sanctum's `can()` is true for
everything on `*` tokens (every normal login), and a test proves normal tokens are still limited.
NFR-1/2 keep think times that would stay under the limits anyway.

### D61. ZAP scans use a read-only API spec
ZAP's OpenAPI job sends a request for *every* operation. The first scan therefore ran
`DELETE /api/admin/courses/{id}` with the admin token and soft-deleted a seeded course, which was
restored. `scripts/zap-baseline.sh` now strips every non-GET operation from the spec before the import and
refuses to run if any remain. The scan is passive ("baseline").

### D62. Findings fixed from the ZAP scan
- Unauthenticated API calls without `Accept: application/json` tried to redirect to a non-existent
  `login` route (500). Guests now always get `401 {"message":"Unauthenticated."}`.
- 404s no longer name model classes ("No query results for model [App\Models\…]").
- `X-Powered-By` / `Server` removed at the gateway and in the app.
- CSP `connect-src` no longer uses the `wss:` scheme wildcard: `'self'` covers same-host WebSockets.
- Accepted: `style-src 'unsafe-inline'` (ZAP medium). Sonner and vaul inject their CSS as `<style>`
  elements at runtime; hash allow-listing would break with every library update. Inline styles cannot
  execute script, and `script-src` stays `'self'` only. Final scan: 0 high, 1 medium (this one),
  0 low.

### D63. Deadlock when many users post in one chat
The NFR-2 load test (50 senders, 10 chats) showed 11 of 2,023 sends failing with MySQL deadlocks. Inserting
a message takes a shared lock on the conversation row (foreign-key check), then bumping
`last_message_id` needs an exclusive lock, so two concurrent senders deadlocked. `ChatService::send`
now locks the conversation row first (`SELECT … FOR UPDATE`, one lock order) and lets Laravel retry a
deadlocked transaction up to 3 times; the receipts transaction is retried too. NFR-2 was re-measured
after the fix (see TEST_REPORT.md).

### D64. Graceful drain when an API instance is stopped
The first NFR-5 runs lost 2–12 requests (HTTP 502) right at the moment of `pm2 stop`. Cause: PM2's default
`treekill` signals every child process at once, so Octane quit while Traefik was still sending it traffic.
`run-api.sh` now stops the heartbeat first (the instance leaves the registry), keeps serving for
`API_DRAIN_SECONDS` (default 4 s, longer than `registry:sync` at 2 s plus Traefik's 1 s throttle) and only
then stops Octane. The api app sets `treekill: false` and `kill_timeout: 12000` so PM2 lets the script
do this. A planned stop now loses no requests (re-measured: 0 of 7,031 failed). A crash (`kill -9`) is
still covered by Traefik's health check and the `retry` middleware, as before.

### D65. Load-test accounts cannot be used outside development
`LoadTestSeeder` and `loadtest:tokens` refuse to run unless `APP_ENV` is `local` or `testing`. The 505
load-test users get a random 40-character password, because nobody signs in as them: k6 uses the
tokens from `loadtest:tokens`. Without this, running the seeder on a real deployment would have created
accounts with a known password and tokens that skip rate limits.

### D66. Single-server deployment on Laravel Forge
For a production server managed by Forge, UniConnect uses what Forge manages instead of the local
distributed stack: Nginx is the gateway (static PWA, `/api` → Octane, `/app` → Reverb), Forge's MySQL is
the only database (`DB_REPLICA_*` point at the primary) and uploads stay on the server's disk
(`FILESYSTEM_DISK=local`). Local-disk files are served by a signed route, `GET /api/files/{path}`
(`signed:relative`, so it verifies behind any proxy host), with the same 5-minute expiry, file name and
content type as the S3 presigned URLs. Laravel's built-in local `temporaryUrl` would lose the name and
type. System Status reports "no replica configured" and "files on local disk" as healthy instead of
failing. The daemons reuse `scripts/run-*.sh`, so the registry and System Status keep working, and
the deploy script does not run `config:cache`, because each process's identity comes from environment
variables. Guide: [forge.md](forge.md).
Forge enables zero-downtime deployments by default and they can't be turned off afterwards, so
`deploy-zero-downtime.sh` supports them. `backend/storage` and the FrankenPHP binary live in
`shared/` and are linked into each release. Daemons run from `current`; after `$ACTIVATE_RELEASE()`
the script sends SIGTERM to every UniConnect process whose working directory is an older release, and
Supervisor restarts it in the new one. A running process can't follow the moved `current` link, so
`octane:reload` alone would keep serving the old release.
