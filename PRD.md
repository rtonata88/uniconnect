# PRD: UniConnect — Distributed Learning and Communication Platform

> **For Claude Code.** This is the full build spec for a Master's project (IUM, Distributed and Mobile Systems). Build everything in this document. Work phase by phase (section 14). Commit after each phase. Keep `make up` working at every commit. **Do not use Docker anywhere** — every service runs natively on the host and is managed by PM2 (section 5). Where something is unclear, pick the simplest choice that still meets the acceptance criteria and write it down in `docs/decisions.md`.

---

## 1. Summary

UniConnect is one platform for lecturers and students. It combines:

- **An LMS**: courses, lecture notes, assignments, quizzes, grades, announcements.
- **WhatsApp-like messaging**: private chats, group chats, course group chats, file sharing, sent / delivered / read ticks, typing indicators, online presence.
- **Notifications**: in-app, real-time, and Web Push (browser and installed PWA, including on phones).
- **Mobile**: one React codebase delivered as a responsive web app that installs on phones as a **Progressive Web App (PWA)**. No native app and no Capacitor. Works offline and syncs when the connection comes back.
- **Distributed architecture**: API gateway with service discovery, several API replicas, horizontally scaled WebSocket servers, a primary/replica database, Redis, object storage and background workers. All services run natively (no Docker), managed by PM2, and instances can also run on other machines on the same network.
- **Security**: token auth, role-based access control, per-course authorization, encrypted message bodies, private files behind signed URLs, rate limiting, audit log.

The project is marked on: requirements and design (25%), LMS and messaging (30%), distributed and mobile computing (20%), security and testing (15%), documentation and presentation (10%). Every feature below maps to one of those.

---

## 2. Users and roles

| Role | Can do |
|---|---|
| **Admin** | Manage users, create courses, assign lecturers, enrol students, view system status and audit log. |
| **Lecturer** | Manage own courses: upload materials, post announcements, create assignments and quizzes, grade, release grades. Chat with anyone in their courses. Moderate course group chats. |
| **Student** | View enrolled courses, download materials (also offline), submit assignments, take quizzes, see released grades. Chat privately, in groups, and in course group chats. |

Roles live on the user (`role`: admin | lecturer | student). Course access is checked against enrolment (`course_user.role`: lecturer | student). A lecturer can only manage courses where they are enrolled as lecturer.

---

## 3. Technology stack

| Layer | Choice | Why |
|---|---|---|
| Frontend | **React 19 + TypeScript + Vite + shadcn/ui + Tailwind CSS** | Required by the user. |
| Frontend data | TanStack Query (server state), Zustand (UI state), React Router, react-hook-form + zod | Standard, works well with shadcn. |
| Offline store | **Dexie (IndexedDB)** | Local replica of courses, materials, chats; outbox for pending writes. |
| Mobile (PWA) | **vite-plugin-pwa (Workbox)** | The mobile client: installable to the home screen on Android and iOS, app-shell caching, offline files, Web Push. No native app. |
| Realtime client | `laravel-echo` + `pusher-js` | Talks to Reverb. |
| Backend API | **Laravel 13 (latest major, released March 2026) on PHP 8.4**, REST JSON, API Resources, Form Requests, Policies | Mature, fast to build. Create the app with `composer create-project laravel/laravel backend` and confirm `laravel/framework` is `^13.0`. Use Laravel 13 conventions and only package versions that support Laravel 13. |
| App server | **Laravel Octane with FrankenPHP** (`php artisan octane:install --server=frankenphp`) | One long-running process per API instance, fast enough for load tests; no Nginx or PHP-FPM needed. |
| Process manager | **PM2** (`ecosystem.config.cjs`) | Starts, restarts, logs and scales every service natively: `pm2 scale api 3`. Replaces Docker. |
| Auth | **Laravel Sanctum** bearer tokens | Works for the web app and the installed PWA. |
| WebSockets | **Laravel Reverb**, Redis scaling enabled | Real-time messaging. Several Reverb instances share state through Redis pub/sub. |
| Queue / jobs | Redis queues + **Laravel Horizon** | Push sending, notification fan-out, file processing. |
| Database | **MySQL 8.4 LTS: one primary + one read replica** (GTID replication, native installs) | Distributed database. Laravel read/write connection split with `sticky => true`. |
| Cache / registry / pub-sub | **Redis 7** (native) | Cache, queues, Reverb scaling, presence, service registry heartbeats. |
| File storage | **MinIO** native binary (S3 compatible) | Separate storage service. Private bucket, signed URLs. |
| Gateway + service discovery | **Traefik v3** native binary with the **Redis KV provider** (plus the file provider for static routes) | Traefik watches Redis; instances that register there are added to load balancing automatically. Health checks, TLS (mkcert certs). |
| Push | Web Push (VAPID) via `laravel-notification-channels/webpush` | Works in desktop browsers, Android Chrome, and iOS/iPadOS 16.4+ when the PWA is installed to the home screen. |
| API docs | **Scribe** (`knuckleswtf/scribe`) | Generates `docs/api` from the code. |
| Tests | Pest (backend), Vitest + React Testing Library (frontend), Playwright (E2E), **k6** (load + WebSocket) | |

---

## 4. Repository layout

```
uniconnect/
  backend/            Laravel 13 app (API, Reverb, Horizon, scheduler, registry sync)
  frontend/           React + Vite + shadcn PWA
  infra/
    traefik/          traefik.yml (static), dynamic/*.yml (file provider), certs/ (mkcert)
    mysql/            primary.cnf, replica.cnf, setup-replication.sh
    minio/            init-bucket.sh
  scripts/            install-deps-ubuntu.sh, install-deps-macos.sh, run-api.sh, run-reverb.sh, check-prereqs.sh
  loadtests/          k6 scripts + run script
  docs/               architecture, API, deployment, user guide, testing, decisions
  ecosystem.config.cjs  PM2 definition of every process
  Makefile            make setup, make up, make down, make seed, make test, make loadtest, make scale, make kill-api
  README.md
```

---

## 5. Architecture

### 5.1 Processes (no Docker)

Every service runs as a native process. PM2 (`ecosystem.config.cjs`) starts and supervises the ones we own; MySQL and Redis run as normal OS services.

| Process | Instances | How it runs | Notes |
|---|---|---|---|
| `traefik` | 1 | PM2 → `traefik --configFile=infra/traefik/traefik.yml` | Ports 80/443 (or 8443 if 443 needs root), dashboard on 8090. Routes `/api/*` → service `api`, `/app/*` and `/apps/*` (Reverb) → service `reverb`, `/` → service `web`. HTTPS with mkcert certs. |
| `api` | **2** (scalable) | PM2 fork mode, `scripts/run-api.sh` | Octane + FrankenPHP. Port = `8001 + PM2 instance id`. Registers itself in Redis on boot (5.2). Health check `GET /api/health`. |
| `reverb` | **2** (scalable) | PM2 fork mode, `scripts/run-reverb.sh` | `php artisan reverb:start --port=<8101 + instance id>`, `REVERB_SCALING_ENABLED=true`. Registers itself in Redis. |
| `registry` | 1 | PM2 → `php artisan registry:sync` | Long-running loop (every 2 s): reads live heartbeats and writes Traefik's server lists into Redis (5.2). |
| `worker` | 1 | PM2 → `php artisan horizon` | Queues. |
| `scheduler` | 1 | PM2 → `php artisan schedule:work` | Deadline reminders. |
| `minio` | 1 | PM2 → `minio server storage/minio --address :9000 --console-address :9001` | `infra/minio/init-bucket.sh` creates the private bucket with `mc`. |
| `web` | 1 | PM2 → `npx serve -s frontend/dist -l 5173` | Production build of the React app. Dev mode can use `vite` instead. |
| MySQL primary | 1 | OS service, port 3306 | Writes. Binlog + GTID. |
| MySQL replica | 1 | Second `mysqld` with its own datadir, port 3307 (or a second machine) | Read-only replica. `infra/mysql/setup-replication.sh` creates the datadir, config, replication user and starts replication. |
| Redis | 1 | OS service, port 6379 | Cache, queues, Reverb pub/sub, service registry, Traefik KV. |

Mail uses `MAIL_MAILER=log` (password-reset links appear in the log). No Mailpit.

**Setup and commands (Makefile):**
- `make setup` — runs `scripts/check-prereqs.sh` (PHP 8.4 with pdo_mysql, redis, pcntl, intl, zip; Composer; Node 22; PM2; MySQL 8.4; Redis 7; MinIO + mc; Traefik v3; mkcert; k6), installs Composer and npm packages, generates keys and certs, sets up replication, creates the bucket, migrates. `scripts/install-deps-ubuntu.sh` and `scripts/install-deps-macos.sh` (Homebrew) install the prerequisites.
- `make up` / `make down` — `pm2 start ecosystem.config.cjs` / `pm2 delete all`.
- `make scale API=3 REVERB=3` — `pm2 scale api 3 && pm2 scale reverb 3`.
- `make kill-api` — stops one API instance (`pm2 stop` on one id) for the failover demo.
- `make status` — `pm2 ls` plus a curl of `/api/admin/system`.

New instances must appear in Traefik and in the in-app System Status page with no config change. This is the **service discovery demo**.

**Multi-machine option (recommended for the demo if a second computer is available):** set `APP_ADVERTISE_HOST=<this machine's LAN IP>` and point `REDIS_HOST`, `DB_*` and `AWS_ENDPOINT` at the first machine, then run `pm2 start ecosystem.config.cjs --only api` on the second computer. Its instance registers in the same Redis and Traefik starts routing to it. This shows real distribution across hosts. Document it in `docs/deployment.md`.

### 5.2 Service discovery (one registry, two consumers)

1. **Registration:** every `api` and `reverb` instance writes a heartbeat every 5 s to Redis key `registry:{service}:{host}:{port}` with a 15 s TTL. The value is JSON: `{service, host, port, url, pid, version, started_at, last_seen, load}`. `worker` and `scheduler` also heartbeat (no URL). An instance that stops heartbeating disappears on its own. On graceful shutdown (SIGTERM) the instance deletes its key at once.
2. **Gateway discovery (Traefik Redis KV provider):** `php artisan registry:sync` rebuilds Traefik's dynamic config in Redis from the live heartbeats: `traefik/http/services/api/loadBalancer/servers/0/url`, `.../1/url`, … as a contiguous list, plus health-check keys (`.../loadBalancer/healthCheck/path` = `/api/health`, interval 5s). Do the same for `reverb`. A WebSocket stays on the instance it first connected to, so no sticky sessions are needed. Remove stale indexes when the list shrinks. Traefik watches these keys and updates routing within seconds. Verify against the Traefik v3 Redis provider docs that the key layout is correct; write any deviation in `docs/decisions.md`.
3. **Application view:** `GET /api/admin/system` reads the same registry and also checks MySQL primary, MySQL replica (replication lag from `SHOW REPLICA STATUS`), Redis and MinIO.

Every API response includes the header `X-Served-By: <host>:<port>` so the demo can show load balancing across instances.

### 5.3 Data flow for one chat message

1. Client creates the message with a `client_uuid` and saves it in the Dexie outbox (status `pending`, one grey clock icon).
2. Client calls `POST /api/conversations/{id}/messages`. Traefik sends it to any `api` replica.
3. API writes to MySQL primary (idempotent on `client_uuid`), returns the saved message (status `sent`, one grey tick).
4. API broadcasts `MessageSent` on `presence-conversation.{id}` through Reverb. Because Reverb scaling uses Redis pub/sub, recipients connected to any Reverb instance receive it.
5. Each recipient client stores the message and calls `POST /api/messages/receipts` with `{delivered: [ids]}`. When all recipients have delivered, the sender sees two grey ticks.
6. When a recipient opens the chat, the client sends `{read: [ids]}`. When all recipients have read, the sender sees two blue ticks.
7. Recipients who are offline get a push notification (queued job). On reconnect, their client pulls missed messages through `/api/sync` and sends delivered receipts.

### 5.4 Diagrams to produce in `docs/architecture.md` (Mermaid)

- Deployment diagram (all processes, hosts and ports, including the optional second machine).
- Component diagram (frontend modules, API modules, external services).
- Sequence: send message with sent/delivered/read.
- Sequence: offline submission → reconnect → sync.
- Sequence: login and channel authorization.
- ER diagram of the database.
- Use case diagram (admin, lecturer, student).

---

## 6. Data model

Use migrations. All tables have `id` (bigint), `created_at`, `updated_at`. Tables that sync to clients also have `deleted_at` (soft deletes) so deletions can be synced. Add indexes on all foreign keys and on `updated_at` for sync tables.

| Table | Key columns |
|---|---|
| `users` | name, email (unique), password, role, student_number (nullable), avatar_path, last_seen_at, is_active |
| `courses` | code (unique), title, description, semester, created_by |
| `course_user` | course_id, user_id, role (lecturer \| student), unique(course_id, user_id) |
| `course_materials` | course_id, uploaded_by, title, description, week, attachment_id, published_at |
| `announcements` | course_id, author_id, title, body, published_at |
| `assignments` | course_id, created_by, title, instructions, due_at, max_score, allow_late (bool), late_penalty_percent, attachment_id (nullable), published_at |
| `submissions` | assignment_id, student_id, client_uuid (unique), text, attachment_id, submitted_at, is_late, score (nullable), feedback, graded_by, graded_at |
| `quizzes` | course_id, title, instructions, opens_at, closes_at, time_limit_minutes, max_attempts, shuffle_questions, published_at |
| `quiz_questions` | quiz_id, type (mcq \| true_false \| short_answer), prompt, points, position, correct_text (for short answer, nullable) |
| `quiz_options` | question_id, text, is_correct, position |
| `quiz_attempts` | quiz_id, student_id, started_at, submitted_at, score, max_score, needs_manual_grading (bool) |
| `quiz_answers` | attempt_id, question_id, option_id (nullable), text_answer (nullable), is_correct (nullable), points_awarded |
| `grade_items` | course_id, title, source_type (assignment \| quiz \| manual), source_id, weight_percent, released_at |
| `grades` | grade_item_id, student_id, score, max_score, comment, graded_by |
| `conversations` | type (direct \| group \| course), title (nullable), course_id (nullable), avatar_path, created_by, last_message_id |
| `conversation_participants` | conversation_id, user_id, role (admin \| member), joined_at, last_delivered_message_id, last_read_message_id, muted_until |
| `messages` | conversation_id, sender_id, client_uuid (unique), type (text \| file \| image \| system), body (**encrypted cast**), attachment_id, reply_to_id, edited_at, deleted_at |
| `message_receipts` | message_id, user_id, delivered_at, read_at, unique(message_id, user_id) |
| `attachments` | owner_id, disk, path, original_name, mime, size_bytes, sha256 |
| `notifications` | Laravel database notifications table |
| `push_subscriptions` | Web Push subscriptions (package table) |
| `audit_logs` | user_id, action, subject_type, subject_id, ip, user_agent, meta (json) |

Rules:
- Creating a course also creates its `course` conversation. Enrolling a user adds them as a participant. Removing enrolment removes them. Lecturers are `admin` participants.
- A `direct` conversation between two users is unique. Opening a chat with someone you already have a chat with returns the existing one.
- Message ordering is by server `id`. Pending (unsent) messages show at the bottom in client creation order.

---

## 7. Functional requirements

IDs are used in tests and in the report traceability matrix. **P0 = must have for the demo.**

### 7.1 Authentication and accounts
- **AUTH-1 (P0)** Login with email + password. Returns a Sanctum token. Rate limit: 5 failed attempts per minute per email+IP.
- **AUTH-2 (P0)** Logout revokes the current token. "Log out all devices" revokes all tokens.
- **AUTH-3** Password reset by email (log mailer in dev).
- **AUTH-4 (P0)** `GET /api/me` returns user, role, and enrolled courses.
- **AUTH-5** Profile: change name, avatar, password.
- **AUTH-6 (P0)** Admin creates users (single and CSV import), sets role, activates/deactivates. Deactivated users cannot log in and their tokens are revoked.

### 7.2 LMS
- **LMS-1 (P0)** Admin creates courses and enrols lecturers and students.
- **LMS-2 (P0)** Course page with tabs: Overview, Materials, Assignments, Quizzes, Grades, Announcements, Chat, People.
- **LMS-3 (P0)** Lecturer uploads lecture notes (PDF, DOCX, PPTX, images, max 20 MB), grouped by week. Students download. Each material has a "Make available offline" toggle.
- **LMS-4 (P0)** Lecturer posts announcements. Enrolled users get a notification.
- **LMS-5 (P0)** Lecturer creates assignments with due date, max score, late policy, optional attachment.
- **LMS-6 (P0)** Student submits text and/or a file. Can resubmit until the due date. After the due date, submission is accepted only if `allow_late`, and is marked late. Submissions can be made offline and are sent when the connection returns (server time decides lateness; the client time is stored in audit meta).
- **LMS-7 (P0)** Lecturer sees all submissions in a table (on time / late / missing), opens each one, gives score and feedback.
- **LMS-8 (P0)** Lecturer builds quizzes: MCQ, true/false, short answer; open/close times; time limit; max attempts; optional shuffle.
- **LMS-9 (P0)** Student takes a quiz with a countdown timer. Auto-submits when time runs out. MCQ and true/false are auto-graded. Short answers are marked for manual grading (exact-match auto-grading if `correct_text` is set, case-insensitive). Quizzes need a connection (show a clear message when offline).
- **LMS-10 (P0)** Gradebook. Lecturer: grid of students × grade items, weighted total, CSV export, "Release grades" per item. Student: sees only released grades and their weighted total.
- **LMS-11** Course dashboard for students: upcoming deadlines, recent announcements, unread chats.

### 7.3 Messaging
- **MSG-1 (P0)** Chat list: all conversations, last message preview, time, unread count, online dot for direct chats. Search by name.
- **MSG-2 (P0)** Private (direct) chat between any two users who share a course (admins can chat with anyone).
- **MSG-3 (P0)** Group chats: create with a name and members, add/remove members (group admins), leave group. System messages for join/leave.
- **MSG-4 (P0)** Course group chat created automatically per course. Lecturer can set it to "announcements only" (only lecturers can post).
- **MSG-5 (P0)** Send text in real time via WebSockets.
- **MSG-6 (P0)** File sharing: images (inline preview), documents (download card with name and size). Max 20 MB. Upload progress bar.
- **MSG-7 (P0)** Delivery status like WhatsApp: clock (pending, not yet on server) → one grey tick (sent) → two grey ticks (delivered to all recipients) → two blue ticks (read by all). In groups, "Message info" shows who received and who read it, with times.
- **MSG-8 (P0)** Typing indicator ("Anna is typing…") via Echo client whispers.
- **MSG-9 (P0)** Presence: online / "last seen at …" for direct chats.
- **MSG-10** Reply to a message (quoted preview). Delete for everyone within 1 hour (shows "This message was deleted").
- **MSG-11** Infinite scroll upward through history (cursor pagination, 50 per page).
- **MSG-12** Mute a conversation (no push, still unread count).

### 7.4 Notifications
- **NOT-1 (P0)** In-app notification centre (bell with count): new announcement, new assignment, new quiz, grade released, deadline reminder, added to group.
- **NOT-2 (P0)** Real-time delivery on `private-user.{id}` so the bell updates without refresh. Toast (shadcn Sonner) for new items.
- **NOT-3 (P0)** Push notifications when the user is not active in the app: Web Push (VAPID) to browsers and installed PWAs, desktop and phone. New message pushes show sender and a short preview. Respect mute.
- **NOT-4 (P0)** Deadline reminders: scheduled job sends a reminder 24 h and 1 h before an assignment is due to students who have not submitted.
- **NOT-5** Notification preferences per category (in-app always on; push on/off).

### 7.5 Mobile and offline
- **MOB-1 (P0)** Fully responsive. Mobile layout uses a bottom tab bar (Courses, Chats, Notifications, Profile). Desktop uses a sidebar. Chat on desktop is two-pane (list + conversation) like WhatsApp Web.
- **MOB-2 (P0)** Installable PWA: web manifest, maskable icons, theme colour, splash, standalone display, an in-app "Install app" button (uses `beforeinstallprompt` on Android/desktop; shows Add-to-Home-Screen steps on iOS). Service worker caches the app shell so the app opens with no network. Prompt to reload when a new version is available.
- **MOB-3 (P0)** Phone-first UX in the installed PWA: safe-area insets, touch targets at least 44 px, pull-to-refresh on lists, camera/file picker for submissions and chat attachments (`<input type="file" accept capture>`), Web Share where available.
- **MOB-4 (P0)** Offline reading: courses, materials list, announcements, assignments, cached chats and messages load from IndexedDB when offline. Materials marked "available offline" open from local storage.
- **MOB-5 (P0)** Offline writing through an outbox: messages, assignment submissions (including the file blob), announcement drafts. Shown as pending. Sent automatically and in order when the connection returns.
- **MOB-6 (P0)** Sync engine (section 9). Shows a status pill: "Online", "Offline – changes will sync", "Syncing…".
- **MOB-7 (P0)** Tapping a Web Push notification opens or focuses the PWA on the right screen (chat or assignment) via the service worker's `notificationclick` handler.

### 7.6 Distributed computing (must be visible in the demo)
- **DIST-1 (P0)** REST API (web services) documented with Scribe.
- **DIST-2 (P0)** Multiple API replicas behind Traefik. `X-Served-By` header. Killing one replica does not stop the app.
- **DIST-3 (P0)** Multiple Reverb replicas. Two users connected to different Reverb instances can chat in real time (verify by logging the instance per connection).
- **DIST-4 (P0)** MySQL primary + replica with read/write split. System page shows replication lag.
- **DIST-5 (P0)** Service discovery: Traefik + Redis registry (section 5.2). Scaling up adds instances automatically.
- **DIST-6 (P0)** Admin **System Status** page: cards for each service and each instance (hostname, status, last heartbeat, uptime), replication lag, queue size, connected WebSocket count. Auto-refresh every 5 s.
- **DIST-7** Client devices act as partial replicas (IndexedDB) that converge with the server through sync.

### 7.7 Security
- **SEC-1 (P0)** Sanctum token auth on every API route except login, password reset, health.
- **SEC-2 (P0)** Authorization with Laravel Policies for every model. Course data requires enrolment. Lecturer actions require lecturer enrolment in that course. Chat access requires being a participant.
- **SEC-3 (P0)** Broadcast channel authorization: `private-user.{id}` only that user; `presence-conversation.{id}` only participants; `private-course.{id}` only enrolled users.
- **SEC-4 (P0)** Files stored in a private MinIO bucket. Downloads go through `GET /api/attachments/{id}`, which checks the policy and redirects to a 5-minute signed URL.
- **SEC-5 (P0)** Upload validation: allow-list of MIME types and extensions, size limit, store under random names, record SHA-256.
- **SEC-6 (P0)** Message bodies encrypted at rest (Eloquent `encrypted` cast). HTTPS through Traefik. HSTS and security headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, CSP on the web app).
- **SEC-7 (P0)** Rate limits: login, message sending (60/min/user), uploads (20/min/user), general API (120/min/user).
- **SEC-8 (P0)** Input validation with Form Requests. Render user text as plain text (no raw HTML).
- **SEC-9 (P0)** Audit log: login success/failure, role changes, enrolment changes, grade changes, grade release, user deactivation. Admin page to view and filter.
- **SEC-10** CORS limited to the web app's origin.
- **SEC-11** Token stored in IndexedDB (via Dexie `meta`) so the service worker and app share it; token expiry 7 days; refresh by re-login; cleared on logout.

---

## 8. API

All routes under `/api`, JSON, bearer token. Use API Resources. Errors use one shape: `{message, errors?}`. Lists are paginated (`cursor` for messages, `page` elsewhere).

```
POST   /auth/login                      POST /auth/logout   POST /auth/logout-all
POST   /auth/forgot-password            POST /auth/reset-password
GET    /me                              PATCH /me           POST /me/avatar
GET    /health                          (no auth; returns hostname, db ok, redis ok)

# Admin
GET|POST         /admin/users           POST /admin/users/import (CSV)
PATCH            /admin/users/{id}
GET|POST         /admin/courses         PATCH|DELETE /admin/courses/{id}
POST|DELETE      /admin/courses/{id}/enrolments
GET              /admin/system          GET /admin/audit-logs

# Courses
GET  /courses                            GET /courses/{id}
GET  /courses/{id}/people
GET|POST          /courses/{id}/materials       PATCH|DELETE /materials/{id}
GET|POST          /courses/{id}/announcements   PATCH|DELETE /announcements/{id}
GET|POST          /courses/{id}/assignments     GET|PATCH|DELETE /assignments/{id}
GET|POST          /assignments/{id}/submissions          (POST = submit, student; GET = list, lecturer)
GET               /assignments/{id}/my-submission
PATCH             /submissions/{id}/grade
GET|POST          /courses/{id}/quizzes         GET|PATCH|DELETE /quizzes/{id}
POST              /quizzes/{id}/attempts        (start)  
PUT               /attempts/{id}/answers        (autosave) 
POST              /attempts/{id}/submit
GET               /quizzes/{id}/attempts        (lecturer)
PATCH             /attempts/{id}/grade          (manual grading of short answers)
GET               /courses/{id}/gradebook       GET /courses/{id}/gradebook/export
POST              /grade-items/{id}/release
GET               /courses/{id}/my-grades

# Messaging
GET  /conversations                       POST /conversations   (direct: {user_id}; group: {title, user_ids})
GET  /conversations/{id}                  PATCH /conversations/{id}
POST /conversations/{id}/participants     DELETE /conversations/{id}/participants/{userId}
POST /conversations/{id}/leave            POST /conversations/{id}/mute
GET  /conversations/{id}/messages?before={id}&limit=50
POST /conversations/{id}/messages         ({client_uuid, type, body?, attachment_id?, reply_to_id?})
DELETE /messages/{id}
POST /messages/receipts                   ({delivered: [ids], read: [ids]})
GET  /messages/{id}/info

# Files
POST /attachments                         (multipart; returns attachment id)
GET  /attachments/{id}                    (policy check → 302 to signed URL)

# Notifications and push
GET  /notifications    POST /notifications/read   (ids or all)
POST /push/webpush/subscribe              DELETE /push/webpush/subscribe
GET|PUT /notification-preferences

# Sync
GET  /sync?since={cursor}
POST /broadcasting/auth
```

### 8.1 WebSocket channels and events

| Channel | Who | Events |
|---|---|---|
| `private-user.{userId}` | that user | `NotificationCreated`, `ConversationCreated`, `ConversationUpdated`, `ReceiptsUpdated` (for the sender's messages) |
| `presence-conversation.{id}` | participants | `MessageSent`, `MessageDeleted`, client whisper `typing` |
| `private-course.{id}` | enrolled users | `AnnouncementPosted`, `MaterialPublished`, `AssignmentPublished`, `QuizPublished` |
| `presence-online` | all logged-in users | presence for online dots |

Broadcast with `ShouldBroadcast` on the queue (Horizon). Use `->toOthers()` where the sender already has the data.

---

## 9. Offline and sync design

### 9.1 Local database (Dexie)

Tables: `courses`, `materials`, `announcements`, `assignments`, `mySubmissions`, `grades`, `conversations`, `messages`, `notifications`, `files` (blobs for offline materials and pending uploads), `outbox`, `meta` (sync cursor, user, token).

### 9.2 Outbox

Each entry: `{id, client_uuid, type ('message' | 'submission' | 'receipt' | ...), payload, file_blob_id?, created_at, attempts, status ('pending' | 'sending' | 'failed'), last_error}`.

- Processed in order (FIFO) when online. Upload file first (`/attachments`), then send the record with the returned `attachment_id`.
- Retry with exponential backoff (1 s, 2 s, 4 s … max 60 s). After 5 failures, mark `failed` and show "Tap to retry".
- 4xx errors other than 408/429 are permanent: mark failed with the server message.
- The server is idempotent on `client_uuid`: a retried request returns the existing record with 200, not a duplicate.

### 9.3 Delta sync

`GET /api/sync?since=<cursor>` returns everything the user can see that changed after the cursor:

```json
{
  "cursor": "2026-10-25T10:15:03.123456Z|981",
  "courses": [...], "materials": [...], "announcements": [...], "assignments": [...],
  "my_submissions": [...], "grades": [...], "conversations": [...],
  "messages": [...],            // max 500, newest per conversation first
  "receipts": [...], "notifications": [...],
  "deleted": { "materials": [ids], "messages": [ids], ... },
  "has_more": false
}
```

- Cursor = `updated_at` + tie-breaker id, so nothing is missed when rows share a timestamp.
- Client loops while `has_more`.
- Sync runs: on app start, on reconnect (browser `online` event, plus a periodic `/api/health` ping because `navigator.onLine` can be wrong), on Reverb reconnect, and every 60 s while online.
- After sync, the client sends delivered receipts for new messages it received.

### 9.4 Conflict rules (document these in the report)

- The server is the source of truth.
- Messages: append-only, idempotent by `client_uuid`. No conflicts possible.
- Submissions: last write before the deadline wins. Lateness uses server receive time.
- Grades, materials, announcements: only lecturers edit; last write wins with `updated_at` check. If the client edits a stale version, the API returns 409 and the client reloads.
- Deletes: soft delete on server, sent in `deleted` so clients remove local copies.

### 9.5 Read path

TanStack Query reads from the API when online and writes results into Dexie. When offline, queries read from Dexie (use a small repository layer: `repo.getCourses()` checks network state and falls back). UI shows an "Offline – showing saved data" banner.

---

## 10. Frontend

### 10.1 Screens
- Login, forgot/reset password.
- **Dashboard** (role-aware): student — upcoming deadlines, recent announcements, unread chats; lecturer — submissions to grade, recent activity; admin — user and course counts, system health summary.
- **Courses list** and **Course page** with tabs (LMS-2).
- Material viewer (PDF inline with browser viewer; others download).
- Assignment detail + submit form; lecturer submissions table + grading drawer.
- Quiz builder (lecturer), quiz taking screen with timer (student), results.
- Gradebook grid (lecturer), My grades (student).
- **Chats**: list + conversation view + new chat dialog + group info sheet + message info sheet.
- Notifications page.
- Profile and settings (notification preferences, install app, log out all devices).
- Admin: Users, Courses & enrolments, **System Status**, Audit log.

### 10.2 UI rules
- shadcn/ui components throughout: Sidebar, Tabs, Card, Table (with TanStack Table for sorting/filtering), Dialog, Sheet, Drawer (mobile), Form, Badge, Avatar, Sonner, Skeleton, Command (search), DropdownMenu, Progress.
- Light and dark mode.
- Chat bubbles: own messages right, others left; time and ticks bottom-right; date separators; file cards; image thumbnails with lightbox.
- Skeleton loaders, empty states with a short helpful line, error states with retry.
- Accessible: labels on inputs, focus states, keyboard navigation in chat (Enter to send, Shift+Enter newline).

### 10.3 Project structure
```
frontend/src/
  app/ (router, providers, layouts)
  features/ auth, dashboard, courses, materials, assignments, quizzes, grades, chat, notifications, admin, system
  lib/ api client (axios with token + X-Request-Id), echo.ts, db.ts (Dexie), sync/ (engine, outbox), push/
  components/ui (shadcn), components/ (shared)
```

---

## 11. Non-functional requirements and targets

Measure these with k6 and report the real numbers. **Never invent results.** If a target is missed, report it and explain why.

| ID | Requirement | Target |
|---|---|---|
| NFR-1 | REST latency, 100 virtual users, mixed reads | p95 < 300 ms, error rate < 1% |
| NFR-2 | Send message REST call under load (50 senders) | p95 < 400 ms |
| NFR-3 | End-to-end message latency (send → received on another socket) with 200 open sockets | p95 < 500 ms |
| NFR-4 | Concurrent WebSocket connections across 2 Reverb instances | ≥ 500 stable for 2 min |
| NFR-5 | Availability: one API replica stopped during load | error rate < 2%, recovers within 15 s |
| NFR-6 | Sync after 50 offline messages | all delivered, no duplicates, order preserved |
| NFR-7 | Scaling: API 2 → 3 replicas | throughput increase recorded |
| NFR-8 | Lighthouse (mobile) on the PWA | PWA installable; performance ≥ 80 |

---

## 12. Testing (deliverable: testing and performance evaluation report)

### 12.1 Automated tests
- **Backend (Pest):** feature tests for every endpoint in section 8, including authorization (student cannot grade, non-participant cannot read chat, non-enrolled user cannot see course), idempotency (`client_uuid` replay), late submission rules, quiz auto-grading and time limit, receipts state changes, broadcast channel auth, rate limits, file policy and signed URL. Target ≥ 80% line coverage on `app/` (report actual).
- **Frontend (Vitest + RTL):** outbox processing order and retries, sync cursor loop, tick status component, quiz timer auto-submit, offline repository fallback.
- **E2E (Playwright):** (1) lecturer creates assignment → student gets notification → submits → lecturer grades → student sees released grade; (2) two browser contexts chat in real time and see ticks change to blue; (3) go offline in the browser, send 3 messages and a submission, go online, verify they sync; (4) student cannot open a course they are not enrolled in.
- **Security checks:** run `composer audit` and `npm audit`; run OWASP ZAP baseline scan against the local stack if available (document if not); manual checklist in `docs/testing/security-checklist.md` against OWASP Top 10.

### 12.2 Performance and distributed tests (k6, in `loadtests/`)
- `rest-mixed.js` — NFR-1.
- `send-messages.js` — NFR-2.
- `ws-latency.js` — NFR-3/NFR-4. Opens sockets with the Pusher protocol to Reverb, authenticates private channels via `/api/broadcasting/auth`, one sender posts messages with a timestamp in the body, receivers measure delay.
- `failover.sh` — runs `rest-mixed.js` and stops one `api` instance midway with `pm2 stop` (NFR-5).
- `scale.sh` — runs the same load at 2 and 3 API replicas (NFR-7).
- Seed a load-test dataset first (`php artisan db:seed --class=LoadTestSeeder`: 500 students, 10 courses, 1 000 messages).

### 12.3 Report output
- `make test` runs all automated tests and writes JUnit/coverage output to `docs/testing/results/`.
- `make loadtest` runs all k6 scripts and writes `--summary-export` JSON to `docs/testing/results/`.
- `php artisan report:testing` (or a Node script) reads those results and writes **`docs/testing/TEST_REPORT.md`** with: environment (CPU, RAM, OS, PHP, MySQL and Node versions), test counts and pass/fail, coverage, a table per NFR (target vs actual, pass/fail), and short notes. Use only real measured values.

---

## 13. Documentation deliverables (write as you build)

- `README.md`: what it is, features, screenshots placeholders, quick start (`make setup && make up && make seed`), demo accounts, URLs (app, Traefik dashboard, Horizon, MinIO console).
- `docs/architecture.md`: all Mermaid diagrams from 5.4, plus explanations of service discovery, replication, Reverb scaling, sync.
- `docs/api/`: Scribe output; plus `docs/realtime.md` for channels and events.
- `docs/deployment.md`: native install on Ubuntu and macOS, PM2 processes, MySQL replication setup, env vars, mkcert HTTPS certs, scaling commands, multi-machine option, VAPID key generation, installing the PWA on Android and iOS (HTTPS is required; use mkcert and trust the CA on the phone, or expose the app over the LAN with a trusted cert).
- `docs/user-guide.md`: short guides per role.
- `docs/testing/`: test plan, security checklist, results, TEST_REPORT.md.
- `docs/decisions.md`: short records of design decisions and trade-offs.
- `docs/demo-script.md`: step-by-step demo for the presentation (section 15).

---

## 14. Build phases (do in this order; each phase ends with green tests and a commit)

1. **Infrastructure and skeleton.** Monorepo, prerequisite scripts, `ecosystem.config.cjs` with all processes, Traefik (Redis KV + file provider), registry heartbeats and `registry:sync`, MySQL replication working, MinIO bucket, Laravel 13 with Octane and the Vite app running behind Traefik, `/api/health` with `X-Served-By`, shadcn installed with layout shell (sidebar + bottom tabs), dark mode.
2. **Auth and users.** Sanctum login/logout, `/me`, roles, policies base, admin user management, audit log foundation, rate limiting.
3. **Courses and LMS core.** Courses, enrolments, materials with uploads (MinIO, signed URLs), announcements, assignments, submissions, grading.
4. **Quizzes and gradebook.** Builder, taking with timer, auto-grading, manual grading, gradebook, release, CSV export.
5. **Messaging.** Conversations (direct, group, course), messages, attachments, Reverb with 2 replicas, presence, typing, receipts and ticks, message info, reply, delete, pagination.
6. **Notifications.** Database notifications, real-time bell, toasts, Web Push (VAPID), scheduled deadline reminders, preferences, mute.
7. **Offline and PWA.** Dexie, repository layer, outbox, `/api/sync`, status pill, offline materials, PWA manifest, service worker, install flow, push click handling, update prompt, phone UX (MOB-3).
8. **Distributed demo features.** Redis service registry, System Status page, scaling scripts, verify Reverb cross-instance delivery, replication lag display.
9. **Security hardening.** Headers, CSP, CORS, message encryption, upload checks, audit log UI, `composer audit`/`npm audit` clean or documented.
10. **Testing and performance.** Complete Pest, Vitest, Playwright suites; k6 scripts; run them; generate TEST_REPORT.md.
11. **Docs and demo.** All documents in section 13, seeded demo data, final README.

If time is short, P0 items in every phase come first. Do not skip phases 8 and 10: they carry 35% of the marks between them.

---

## 15. Seed data and demo script

`make seed` creates:
- Admin: `admin@uniconnect.test`
- Lecturers: `mziezo@uniconnect.test`, `jshikongo@uniconnect.test`
- Students: `student1@uniconnect.test` … `student10@uniconnect.test`
- All passwords: `Password123!`
- Courses: `DMS901 Distributed and Mobile Systems`, `SE902 Advanced Software Engineering`, `DB903 Advanced Databases`, each with weekly materials (small sample PDFs generated by the seeder), 2 announcements, 2 assignments (one due in 2 days, one past due), 1 quiz, and a course chat with some history. A few direct and group chats.

`docs/demo-script.md` (about 10 minutes):
1. Show architecture diagram, then `pm2 ls` and the Traefik dashboard (services discovered from Redis).
2. Log in as lecturer (desktop) and student (the PWA installed on a phone). Show `X-Served-By` alternating in dev tools.
3. Lecturer posts an assignment → student gets a toast and push.
4. Student and lecturer chat: typing indicator, ticks grey → blue, send a PDF.
5. Course group chat with a third user; open "Message info".
6. Turn on airplane mode on the student device: browse cached course, open offline material, send 2 messages and submit the assignment. Turn network back on: watch them sync, ticks update.
7. Lecturer grades and releases; student sees the grade.
8. Admin System Status: run `make scale API=3`, watch the new instance appear in Traefik and System Status; run `make kill-api`, app keeps working. Optional: start an API instance on a second computer and watch it join.
9. Show test results and load test numbers from TEST_REPORT.md.
10. Security: show a student trying to open another course's URL (403), show encrypted message body in the database.

---

## 16. Acceptance criteria (definition of done)

- `make setup && make up && make seed` on a clean Ubuntu 24.04 or macOS machine gives a working system at `https://localhost`. No Docker is used anywhere.
- Every P0 requirement works and has at least one automated test.
- Two users on different Reverb instances chat in real time with correct ticks.
- Offline messages and submissions sync with no duplicates.
- Scaling and failover work as in the demo script.
- The PWA installs on an Android phone and on desktop Chrome, receives Web Push, works offline and syncs.
- `docs/testing/TEST_REPORT.md` exists with real measured numbers.
- All documents in section 13 exist and are accurate.

## 17. Out of scope

Native mobile apps (Android/iOS, Capacitor, React Native), end-to-end encryption, voice/video calls, plagiarism detection, SSO with university systems, payments.
