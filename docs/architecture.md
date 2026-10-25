# UniConnect — architecture

UniConnect is a learning platform (courses, materials, assignments, quizzes, gradebook) with
WhatsApp-style messaging, real-time notifications and an offline-capable installable web app (PWA).
It is built as a set of native processes managed by PM2 — no containers — that can be spread over
several machines on the same network.

- [1. Deployment](#1-deployment)
- [2. Components](#2-components)
- [3. Service discovery](#3-service-discovery)
- [4. Database replication](#4-database-replication)
- [5. Real-time messaging and Reverb scaling](#5-real-time-messaging-and-reverb-scaling)
- [6. Offline and sync](#6-offline-and-sync)
- [7. Security architecture](#7-security-architecture)
- [8. Sequences](#8-sequences)
- [9. Data model](#9-data-model)
- [10. Use cases](#10-use-cases)

## 1. Deployment

Every box is a native OS process. PM2 (`ecosystem.config.cjs`) starts and supervises all of them,
including the two MySQL servers (decision D2). Ports are the defaults from `backend/.env.example`;
each can be changed in `backend/.env`.

```mermaid
flowchart LR
  subgraph Clients
    B1["Browser / installed PWA<br/>(desktop)"]
    B2["Installed PWA<br/>(Android / iOS)"]
  end

  subgraph H1["Machine 1 (gateway + data)"]
    T["Traefik v3<br/>:443 HTTPS · :80 → 443<br/>:8090 dashboard (localhost)<br/>:8092 internal"]
    subgraph API["api × N (PM2 fork, scalable)"]
      A1["Octane + FrankenPHP<br/>:8001"]
      A2["Octane + FrankenPHP<br/>:8002"]
    end
    subgraph RV["reverb × N (scalable)"]
      R1["Reverb :8101"]
      R2["Reverb :8102"]
    end
    REG["registry:sync<br/>(every 2 s)"]
    W["Horizon workers<br/>queues: broadcasts, default, push"]
    S["scheduler<br/>(reminders, quiz auto-submit)"]
    WEB["web — static PWA build<br/>:5173"]
    M1[("MySQL 8.4 primary<br/>:3306 · binlog + GTID")]
    M2[("MySQL 8.4 replica<br/>:3307 · read only")]
    RD[("Redis<br/>:6379")]
    MI[("MinIO S3<br/>:9000 · console :9001 (localhost)")]
  end

  subgraph H2["Machine 2 (optional)"]
    A3["Octane + FrankenPHP<br/>:8001"]
  end

  B1 & B2 -- "HTTPS + WSS (one origin)" --> T
  T -- "/api/*" --> A1 & A2 & A3
  T -- "/app/*, /apps/*" --> R1 & R2
  T -- "/uc-private/* (presigned)" --> MI
  T -- "/" --> WEB
  A1 & A2 & A3 -- "writes" --> M1
  A1 & A2 & A3 -- "reads (sticky)" --> M2
  M1 -. "GTID replication" .-> M2
  A1 & A2 & A3 & R1 & R2 & W & S -- "heartbeat registry:*" --> RD
  REG -- "reads registry, writes traefik/* KV" --> RD
  T -- "watches traefik/* (keyspace events)" --> RD
  R1 <-- "pub/sub scaling" --> RD
  R2 <-- "pub/sub scaling" --> RD
  A1 & A2 -- "queue jobs" --> RD
  W -- "broadcast via :8092" --> T
  A1 & A2 & W -- "S3 API" --> MI
```

| Process | PM2 name | Instances | Port | Role |
|---|---|---|---|---|
| Traefik v3 | `traefik` | 1 | 443 / 80 / 8090 / 8092 | gateway, TLS, load balancing, health checks, retry |
| API | `api` | 2 (scalable) | 8001 + instance id | Laravel 13 on Octane + FrankenPHP (4 workers each) |
| Reverb | `reverb` | 2 (scalable) | 8101 + instance id | WebSockets (Pusher protocol), Redis pub/sub scaling |
| Registry sync | `registry` | 1 | — | heartbeats → Traefik Redis KV |
| Horizon | `worker` | 1 (2–4 broadcast processes + 1–3 default/push) | — | broadcasts, notifications, Web Push |
| Scheduler | `scheduler` | 1 | — | `assignments:remind`, `quizzes:close-expired` |
| MinIO | `minio` | 1 | 9000 / 9001 | private object storage (S3 API) |
| Web | `web` | 1 | 5173 | production build of the PWA |
| MySQL primary | `mysql-primary` | 1 | 3306 | writes, binlog, GTID |
| MySQL replica | `mysql-replica` | 1 | 3307 | read-only replica |
| Redis | OS service | 1 | 6379 | cache, queues, pub/sub, registry, Traefik KV |

A second machine only needs PHP and the backend checked out: it runs `pm2 start ecosystem.config.cjs --only api`
with `APP_ADVERTISE_HOST` set to its LAN IP and `REDIS_HOST`, `DB_*` and `AWS_ENDPOINT` pointing at
machine 1. Its instances register in the same Redis and Traefik starts routing to them
(see [deployment.md](deployment.md#multi-machine)).

## 2. Components

```mermaid
flowchart TB
  subgraph FE["Frontend — React 19 + Vite + shadcn/ui (PWA)"]
    direction TB
    UI["Screens<br/>dashboard · courses · materials · assignments<br/>quizzes · grades · chats · notifications · admin · system"]
    TQ["TanStack Query"]
    REPO["Repository (online → API, offline → IndexedDB)"]
    DX[("Dexie / IndexedDB<br/>courses, materials, announcements, assignments,<br/>mySubmissions, grades, conversations, messages,<br/>notifications, files, outbox, meta")]
    OB["Outbox (FIFO, retries, backoff)"]
    SE["Sync engine (GET /api/sync loop)"]
    RT["Realtime (laravel-echo + pusher-js)"]
    SW["Service worker (Workbox)<br/>app-shell precache · image cache · Web Push"]
    UI --> TQ --> REPO --> DX
    UI --> OB --> DX
    SE --> DX
    RT --> DX
  end

  subgraph BE["Backend — Laravel 13"]
    direction TB
    MW["Middleware<br/>Sanctum · role · activity · throttle · CORS<br/>security headers · X-Served-By"]
    CT["Controllers + Form Requests + Policies"]
    RS["API Resources"]
    SV["Services<br/>ChatService · QuizGrader · Gradebook · Enrolment<br/>AttachmentService · FileUrlSigner · SystemStatus · Audit"]
    EV["Events / broadcasts / notifications"]
    REGS["ServiceRegistry · TraefikKvWriter · heartbeat commands"]
    MW --> CT --> SV --> RS
    SV --> EV
  end

  REPO -- "REST JSON (Bearer)" --> MW
  OB -- "REST (idempotent client_uuid)" --> MW
  SE -- "/api/sync" --> MW
  RT -- "WSS + /api/broadcasting/auth" --> REVERB["Reverb"]
  EV -- "queued broadcast" --> REVERB
  EV -- "Web Push (VAPID)" --> PUSH["Browser push services<br/>(FCM, Mozilla, Apple, WNS)"]
  PUSH --> SW
  SV --> MYSQL[("MySQL primary / replica")]
  SV --> S3[("MinIO")]
  SV --> REDIS[("Redis")]
  REGS --> REDIS
```

Backend modules (in `backend/app`):

| Area | Main classes |
|---|---|
| Auth & users | `AuthController`, `PasswordResetController`, `MeController`, `Admin\UserController`, `UserPolicy`, `Audit` |
| LMS | `CourseController`, `MaterialController`, `AnnouncementController`, `AssignmentController`, `SubmissionController`, `QuizController`, `QuizAttemptController`, `GradebookController`, `Support\Lms\{QuizGrader,Gradebook}`, `Support\Enrolment` |
| Messaging | `ConversationController`, `MessageController`, `ContactController`, `Support\Chat\ChatService`, events `MessageSent`, `MessageDeleted`, `ConversationChanged`, `ReceiptsUpdated` |
| Notifications | `Notifications\*`, `Channels\RealtimeChannel`, `Listeners\Send*Notifications`, `SendDeadlineReminders`, `PushSubscriptionController` |
| Files | `AttachmentController`, `Support\Files\{AttachmentService,FileUrlSigner}`, `AttachmentPolicy` |
| Sync | `SyncController` |
| Distributed | `Support\Registry\*`, `RegistryHeartbeat`, `RegistrySync`, `Support\System\SystemStatus`, `Admin\SystemController`, `AddServedBy` |

## 3. Service discovery

One registry, two consumers (PRD 5.2).

1. **Registration.** Every instance writes `registry:{service}:{host}:{port}` to Redis every 5 s with a
   15 s TTL. The value is `{service, host, port, url, pid, version, hostname, started_at, last_seen, load}`.
   - `api`: a sidecar (`php artisan registry:heartbeat api --port --pid --health`) started by
     `scripts/run-api.sh` next to Octane. It only advertises the instance while `GET /api/health` returns 2xx.
   - `reverb`: registers from inside its own event loop (`ReverbHeartbeat`) and also reports its
     current number of connections.
   - `worker`, `scheduler`: the same sidecar without a URL (`scripts/run-with-heartbeat.sh`).
   - On SIGTERM (PM2 stop) the key is deleted at once; if a process dies, the key expires.
2. **Gateway.** `php artisan registry:sync` (PM2 `registry`, every 2 s) turns the live heartbeats into
   Traefik's Redis-KV layout:
   ```
   traefik/http/services/api/loadBalancer/servers/0/url = http://127.0.0.1:8001
   traefik/http/services/api/loadBalancer/servers/1/url = http://127.0.0.1:8002
   traefik/http/services/api/loadBalancer/healthCheck/path = /api/health   (interval 5s, timeout 3s)
   traefik/http/services/reverb/loadBalancer/servers/…  (health /up)
   ```
   The list is contiguous, only changed keys are written (so Traefik reloads only on membership
   changes), new servers are added before stale indexes are removed, and keyspace notifications are
   switched on because the Redis provider needs them. Routers live in the file provider
   (`infra/traefik/dynamic/routes.yml`) and point at `api@redis` / `reverb@redis`.
3. **Application view.** `GET /api/admin/system` reads the same registry plus Traefik's own
   health-checked view and checks MySQL, the replica, Redis, MinIO, queues and sockets; the System Status
   page polls it every 5 s.

Failover: Traefik's `retry` middleware resends a request whose connection to a backend failed (no
response yet) to another server, which covers the ≤ 3 s between an instance dying and the next sync.
`X-Served-By: host:port` on every response shows which replica answered.

## 4. Database replication

- Primary (`server-id=1`, binlog, GTID) and replica (`server-id=2`, `read_only`, `super_read_only`) are
  set up by `infra/mysql/setup-replication.sh` with `CHANGE REPLICATION SOURCE TO … SOURCE_AUTO_POSITION=1`.
- Laravel's `mysql` connection has a `read` host (replica) and `write` host (primary) with `sticky => true`:
  after a write, the rest of that request reads from the primary (read-your-writes). Everything else reads
  from the replica.
- Lag is shown two ways on the System Status page: the replica's `Seconds_Behind_Source` and a measured
  value (a token written to `replication_probes` on the primary, polled on the replica — typically 20–30 ms
  on one machine). Monitoring uses a separate non-sticky `mysql_replica` connection (D50).

## 5. Real-time messaging and Reverb scaling

- Clients connect to `wss://<host>/app/<key>`; Traefik balances the upgrade to any Reverb instance. A
  WebSocket stays on the instance it reached, so no sticky sessions are needed.
- Reverb runs with `REVERB_SCALING_ENABLED=true`: an event published to any instance goes through Redis
  pub/sub (`uniconnect-reverb` channel) to all instances, so users on different instances chat in real
  time (`make verify-realtime`).
- The API never talks to a specific Reverb: Horizon workers publish through Traefik's internal
  entrypoint (`:8092`), which balances over the discovered instances (D9).
- Broadcasts run on a dedicated `broadcasts` queue with blocking pops; measured REST send → frame on
  another instance ≈ 25 ms (D35).

Channels:

| Channel | Who | Events |
|---|---|---|
| `private-user.{id}` | that user | `NotificationCreated`, `ConversationCreated`, `ConversationUpdated`, `ReceiptsUpdated` |
| `presence-conversation.{id}.{version}` | current participants | `MessageSent`, `MessageDeleted`, whisper `typing` |
| `private-course.{id}` | enrolled users | `AnnouncementPosted`, `MaterialPublished`, `AssignmentPublished`, `QuizPublished` |
| `presence-online` | signed-in users (ids only) | presence for online dots |

The conversation channel name carries a version that changes when a member is removed, so a removed
user's open subscription stops receiving (D39). See [realtime.md](realtime.md) for payloads.

### Delivery status (ticks)

```mermaid
stateDiagram-v2
  [*] --> Pending: stored in IndexedDB + outbox (clock)
  Pending --> Sent: POST accepted (one grey tick)
  Pending --> Failed: 5 failures or permanent 4xx ("Tap to retry")
  Failed --> Pending: retry
  Sent --> Delivered: every recipient reported delivered (two grey ticks)
  Delivered --> Read: every recipient reported read (two blue ticks)
  Sent --> Read: recipient had the chat open
```

## 6. Offline and sync

The device is a partial replica of the server (DIST-7):

- **Read path:** screens read through a repository. Online it fetches, stores in IndexedDB and returns;
  offline (or when a request fails without a response) it reads IndexedDB and the UI says
  "Offline – showing saved data". Chats always render from IndexedDB.
- **Write path:** messages, assignment submissions (with the file blob) and announcement drafts go to the
  outbox, which is processed FIFO when online: upload the file, then send the record with
  `attachment_id`; retries back off 1 s, 2 s, 4 s … 60 s; after 5 failures (or a permanent 4xx) the entry is
  marked failed. The server is idempotent on `client_uuid`, so a retry never duplicates.
- **Delta sync:** `GET /api/sync?since=<updated_at|id>` returns what changed (courses, materials,
  announcements, assignments, my submissions, released grades, conversations, messages, receipts,
  notifications, deleted ids, memberships). The client loops while `has_more`. Sync runs on start, when the
  connection comes back (browser event or `/api/health` probe), after a Reverb reconnect and every 60 s.
  A 2-second overlap per round covers transactions that commit late; messages are keyset-paginated
  by `(updated_at, id)` (D46).
- **Conflicts:** the server is the source of truth. Messages are append-only (no conflicts). Submissions:
  last write before the deadline wins; lateness uses the server receive time; a graded submission is
  final. Lecturer-edited records (materials, announcements, assignments, quizzes, grade items) carry
  `updated_at`; editing a stale version returns 409 and the client reloads. Deletions are soft and are
  sent in `deleted`.
- **PWA:** the service worker precaches the app shell (the app opens with no network), caches private
  images by path, shows Web Push notifications and focuses/opens the right screen on click.

## 7. Security architecture

| Layer | Controls |
|---|---|
| Transport | HTTPS only (mkcert), HSTS, HTTP→HTTPS redirect at Traefik |
| Identity | Sanctum bearer tokens (7 days), login throttling per email+IP / account+network / IP, deactivation revokes tokens |
| Authorization | Policies for every model; course enrolment, lecturer role per course, chat participation; broadcast channel auth |
| Data | Message bodies encrypted at rest; private bucket; 5-minute presigned URLs signed for the request host; Authorization header stripped on the file route |
| Input | Form Requests / validation, MIME + extension allow-list (libmagic), 20 MB, random names, SHA-256 |
| Browser | CSP (`script-src 'self'`), nosniff, frame options, referrer policy, React text rendering |
| Abuse | Rate limits: login, API 120/min, messages 60/min, uploads 20/min |
| Accountability | Audit log for security-relevant actions + admin UI |
| Operations | Admin consoles bound to localhost, Horizon host-only, debug off |

Details: [testing/security-checklist.md](testing/security-checklist.md).

## 8. Sequences

### 8.1 Send a message (sent → delivered → read)

```mermaid
sequenceDiagram
  autonumber
  actor A as Sender (PWA)
  participant IDB as IndexedDB (sender)
  participant T as Traefik
  participant API as API replica (any)
  participant DB as MySQL primary
  participant Q as Redis queue / Horizon
  participant R1 as Reverb instance 1
  participant R2 as Reverb instance 2
  actor B as Recipient (PWA)

  A->>IDB: message {client_uuid, status pending} + outbox entry (🕓)
  A->>T: POST /api/conversations/{id}/messages {client_uuid, body}
  T->>API: route to any healthy replica
  API->>DB: INSERT (unique client_uuid → idempotent)
  API-->>A: 201 message {id, status: sent} (✓)
  A->>IDB: replace pending row, delete outbox entry
  API->>Q: MessageSent (broadcasts queue)
  Q->>T: POST /apps/{app}/events (internal :8092)
  T->>R1: any Reverb instance
  R1-->>R2: Redis pub/sub
  R2-->>B: MessageSent on presence-conversation.{id}.{v}
  B->>B: store in IndexedDB
  B->>T: POST /api/messages/receipts {delivered:[id]}
  T->>API: receipts → message_receipts
  API->>Q: ReceiptsUpdated (status delivered for all)
  Q-->>A: ReceiptsUpdated on private-user.{sender} (✓✓ grey)
  B->>T: chat opened → POST /api/messages/receipts {read:[id]}
  API->>Q: ReceiptsUpdated (read by all)
  Q-->>A: ✓✓ blue
  Note over API,Q: offline recipients get Web Push (queued job, respects mute)
```

### 8.2 Offline submission → reconnect → sync

```mermaid
sequenceDiagram
  autonumber
  actor S as Student (PWA, offline)
  participant IDB as IndexedDB
  participant OB as Outbox
  participant API as API (via Traefik)
  participant DB as MySQL

  S->>IDB: open course/assignment (served from local copy)
  S->>OB: submit {client_uuid, text, client_submitted_at} + file blob → files table
  OB-->>S: "Waiting to send" (pending)
  Note over S: connection returns (online event or /api/health probe succeeds)
  OB->>API: POST /api/attachments (file blob)
  API-->>OB: {attachment_id}
  OB->>API: POST /api/assignments/{id}/submissions {client_uuid, attachment_id}
  API->>DB: insert or resubmit, lateness by server time, audit client_submitted_at
  API-->>OB: 201 (or 200 if this client_uuid was already stored)
  OB->>IDB: mySubmissions ← server copy, drop blob and outbox entry
  S->>API: GET /api/sync?since=cursor (loop while has_more)
  API-->>S: changes since cursor (grades, announcements, messages, deleted ids…)
  S->>IDB: apply, store new cursor, send delivered receipts
```

### 8.3 Login and channel authorization

```mermaid
sequenceDiagram
  autonumber
  actor U as User
  participant APP as PWA
  participant API as API
  participant RV as Reverb
  U->>APP: email + password
  APP->>API: POST /api/auth/login
  API->>API: throttles (email+IP, account+network, IP) · check password · is_active
  API-->>APP: {token (7 days), user}
  APP->>APP: store token in IndexedDB meta (shared with the service worker)
  APP->>API: GET /api/config (Reverb key, VAPID key)
  APP->>RV: WSS /app/{key} (through Traefik)
  RV-->>APP: connection_established {socket_id}
  APP->>API: POST /api/broadcasting/auth {socket_id, channel: private-user.7} (Bearer)
  API->>API: routes/channels.php: user.{id} === me
  API-->>APP: {auth signature}
  APP->>RV: pusher:subscribe {channel, auth}
  APP->>API: auth presence-conversation.{id}.{v} (participant + current version)
  API-->>APP: {auth, channel_data: {id, name}}
  APP->>RV: subscribe → member list (online / typing)
  APP->>API: GET /api/sync (first sync)
```

## 9. Data model

```mermaid
erDiagram
  users ||--o{ course_user : enrolled
  courses ||--o{ course_user : has
  courses ||--o{ course_materials : has
  courses ||--o{ announcements : has
  courses ||--o{ assignments : has
  courses ||--o{ quizzes : has
  courses ||--o{ grade_items : has
  courses ||--|| conversations : "course chat"
  assignments ||--o{ submissions : receives
  users ||--o{ submissions : submits
  quizzes ||--o{ quiz_questions : contains
  quiz_questions ||--o{ quiz_options : offers
  quizzes ||--o{ quiz_attempts : taken
  quiz_attempts ||--o{ quiz_answers : records
  grade_items ||--o{ grades : holds
  users ||--o{ grades : earns
  conversations ||--o{ conversation_participants : has
  users ||--o{ conversation_participants : joins
  conversations ||--o{ messages : contains
  messages ||--o{ message_receipts : tracked_by
  messages |o--o| messages : "reply_to"
  attachments |o--o{ course_materials : file
  attachments |o--o{ assignments : file
  attachments |o--o{ submissions : file
  attachments |o--o{ messages : file
  users ||--o{ attachments : owns
  users ||--o{ notifications : receives
  users ||--o{ push_subscriptions : registers
  users ||--o{ audit_logs : performs

  users {
    bigint id PK
    string email UK
    string role "admin|lecturer|student"
    bool is_active
    timestamp last_seen_at
    json notification_preferences
  }
  courses {
    bigint id PK
    string code UK
    string title
    string semester
    timestamp deleted_at
  }
  course_user {
    bigint course_id FK
    bigint user_id FK
    string role "lecturer|student"
  }
  course_materials {
    bigint id PK
    bigint course_id FK
    smallint week
    bigint attachment_id FK
    timestamp published_at
    timestamp deleted_at
  }
  announcements {
    bigint id PK
    bigint course_id FK
    string client_uuid UK
    text body
    timestamp published_at
  }
  assignments {
    bigint id PK
    bigint course_id FK
    timestamp due_at
    decimal max_score
    bool allow_late
    tinyint late_penalty_percent
  }
  submissions {
    bigint id PK
    bigint assignment_id FK
    bigint student_id FK
    string client_uuid UK
    bool is_late
    decimal score
    timestamp graded_at
  }
  quizzes {
    bigint id PK
    bigint course_id FK
    timestamp opens_at
    timestamp closes_at
    smallint time_limit_minutes
    tinyint max_attempts
  }
  quiz_attempts {
    bigint id PK
    bigint quiz_id FK
    bigint student_id FK
    timestamp deadline_at
    timestamp submitted_at
    decimal score
    bool needs_manual_grading
  }
  grade_items {
    bigint id PK
    bigint course_id FK
    string source_type "assignment|quiz|manual"
    decimal weight_percent
    timestamp released_at
  }
  grades {
    bigint id PK
    bigint grade_item_id FK
    bigint student_id FK
    decimal score
  }
  conversations {
    bigint id PK
    string type "direct|group|course"
    string direct_key UK
    bigint course_id FK
    bool announcements_only
    int channel_version
  }
  conversation_participants {
    bigint conversation_id FK
    bigint user_id FK
    string role
    bigint last_read_message_id
    timestamp muted_until
  }
  messages {
    bigint id PK
    bigint conversation_id FK
    string client_uuid UK
    text body "encrypted"
    bigint reply_to_id FK
    timestamp deleted_at
  }
  message_receipts {
    bigint message_id FK
    bigint user_id FK
    timestamp delivered_at
    timestamp read_at
  }
  attachments {
    bigint id PK
    bigint owner_id FK
    string path UK
    string mime
    bigint size_bytes
    char sha256
  }
  audit_logs {
    bigint id PK
    bigint user_id FK
    string action
    string subject_type
    json meta
    string ip
  }
```

All tables have `id`, `created_at`, `updated_at`; synced tables also have `deleted_at` and an index on
`updated_at`. Every foreign key is indexed. Full definitions: `backend/database/migrations/`.

## 10. Use cases

```mermaid
flowchart LR
  Admin((Admin))
  Lecturer((Lecturer))
  Student((Student))

  subgraph UniConnect
    UC1([Manage users / CSV import / deactivate])
    UC2([Create courses, assign lecturers, enrol students])
    UC3([View System Status and audit log])
    UC4([Upload materials by week])
    UC5([Post announcements])
    UC6([Create assignments and quizzes])
    UC7([Grade, release grades, export CSV])
    UC8([Moderate course chat])
    UC9([Download materials, also offline])
    UC10([Submit assignments, also offline])
    UC11([Take timed quizzes])
    UC12([See released grades])
    UC13([Chat: private, group, course])
    UC14([Receive notifications and push])
    UC15([Install the app on a phone])
  end

  Admin --- UC1 & UC2 & UC3 & UC13 & UC14
  Lecturer --- UC4 & UC5 & UC6 & UC7 & UC8 & UC13 & UC14 & UC15
  Student --- UC9 & UC10 & UC11 & UC12 & UC13 & UC14 & UC15
```
