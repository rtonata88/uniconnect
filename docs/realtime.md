# Real-time channels and events

UniConnect uses **Laravel Reverb** (Pusher protocol) behind the Traefik gateway. Clients use
`laravel-echo` + `pusher-js`; any Pusher-protocol client works.

## Connecting

| | |
|---|---|
| WebSocket URL | `wss://<APP_URL host>/app/<REVERB_APP_KEY>?protocol=7&client=js&version=8.4.0` |
| App key | `GET /api/config` → `reverb.key` (no auth) |
| Channel auth | `POST /api/broadcasting/auth` with `Authorization: Bearer <token>`, body `{socket_id, channel_name}` |
| Keep-alive | server sends `pusher:ping` every 60 s, answer `pusher:pong`; activity timeout 30 s |
| Instances | Traefik balances new sockets over all registered Reverb instances; Redis pub/sub delivers every event to all of them |

Server-side publishing does not use a fixed Reverb host: Horizon workers post to Reverb's HTTP API
through Traefik's internal entrypoint (`http://127.0.0.1:GATEWAY_INTERNAL_PORT/apps/...`), which picks any
live instance.

All broadcasts are queued on the `broadcasts` Horizon queue. Events caused by a client request are
not echoed back to the sender's own socket when the request carries `X-Socket-ID` (`toOthers`).

## Channels

### `private-user.{userId}`
Authorised for that user only.

| Event | Payload | Sent when |
|---|---|---|
| `NotificationCreated` | `{id, category, title, body, url, course_id?, read_at: null, created_at}` | any in-app notification (announcement, assignment, quiz, grade released, deadline reminder, added to group) |
| `ConversationCreated` | `{conversation_id, reason: "created"}` | the user was added to a conversation (new direct chat, new group, enrolment) |
| `ConversationUpdated` | `{conversation_id, reason: "updated" \| "removed"}` | members, title, mute, announcements-only changed; `removed` = the user lost access |
| `ReceiptsUpdated` | `{receipts: [{message_id, conversation_id, status, delivered, read, recipients}]}` | ticks of the user's own messages changed (`status`: `sent`, `delivered`, `read`) |

Conversation events carry only ids: clients re-fetch `GET /api/conversations/{id}`, which applies the
normal access checks.

### `presence-conversation.{conversationId}.{version}`
Authorised for current participants only. The name comes from the conversation resource
(`channel` field). `version` changes whenever a member is removed or leaves, so an old subscription
stops receiving events and cannot be authorised again (decision D39).

Member info: `{id, name}`.

| Event | Payload |
|---|---|
| `MessageSent` | `{message: Message}` (below) |
| `MessageDeleted` | `{id, conversation_id}` |
| client whisper `typing` | `{id, name, typing: true \| false}` (sent at most every 2.5 s while typing; `false` after 3 s idle) |

`Message`:
```json
{
  "id": 1042,
  "conversation_id": 7,
  "client_uuid": "4c0f8e0e-6f9a-4a3d-9a67-0f1f2a6b1c55",
  "type": "text",
  "sender": { "id": 2, "name": "Dr. Maria Ziezo" },
  "body": "See you in the lab",
  "attachment": null,
  "reply_to": { "id": 1040, "sender_name": "Anna", "type": "text", "excerpt": "Is the lab today?" },
  "deleted": false,
  "status": null,
  "created_at": "2026-10-25T10:15:03.123Z",
  "updated_at": "2026-10-25T10:15:03.000000Z"
}
```
`attachment`, when present: `{id, name, mime, size, is_image, sha256, url}` where `url` is
`/api/attachments/{id}` (policy check → 5-minute presigned URL).

### `private-course.{courseId}`
Authorised for users enrolled in the course.

| Event | Payload |
|---|---|
| `AnnouncementPosted` | `{id, course_id, title}` |
| `MaterialPublished` | `{id, course_id, title}` |
| `AssignmentPublished` | `{id, course_id, title}` |
| `QuizPublished` | `{id, course_id, title}` |

### `presence-online`
Every signed-in client joins it. Member info is `{id}` only (no names). Used for the online dots and,
on the server, to decide whether a user is "active in the app" (no Web Push then).

## Receipts (MSG-7)

Receipts are plain REST, not socket messages:

```
POST /api/messages/receipts   {"delivered": [1042, 1043], "read": [1042]}
```

- *delivered*: the message is stored on the device (sent after a `MessageSent` event or after a sync).
- *read*: the message was on screen in the open chat.

Clients batch receipts in one outbox entry. The server records them in `message_receipts`, moves the
participant's watermarks (unread counts) and pushes `ReceiptsUpdated` to the sender when the
aggregate status changes.

## Push (outside the app)

When a user is not connected to `presence-online`, notifications and chat messages are also sent as
Web Push (VAPID). The payload is shown by the service worker:
```json
{ "title": "Dr. Maria Ziezo @ DMS901 Distributed and Mobile Systems", "body": "Lab moved to Thursday",
  "icon": "/pwa-192x192.png", "badge": "/pwa-64x64.png", "tag": "messages:/chats/1",
  "data": { "url": "/chats/1", "category": "messages" } }
```
Tapping it focuses the open app (or opens it) on `data.url`.

## Verifying across instances

```bash
make verify-realtime
# A mziezo@… → Reverb 127.0.0.1:8202   B student1@… → Reverb 127.0.0.1:8201
# B received 5/5 across instances; latency ms: 37, 35, 20, 20, 12
```
Each Reverb instance logs `[reverb host:port] socket <id> subscribed to <channel>` (PM2 log `reverb`),
which shows which instance served which connection.
