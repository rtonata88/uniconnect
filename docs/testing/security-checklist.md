# Security checklist — OWASP Top 10 (2021)

Manual review of UniConnect against the OWASP Top 10, with the controls in place, where they are
implemented and how they are verified. Requirement IDs refer to `PRD.md` §7.7. Automated evidence is
in `backend/tests/Feature/*` (Pest) and `docs/testing/results/`.

| | Status |
|---|---|
| ✅ | control implemented and covered by an automated test |
| ☑️ | control implemented, verified manually |
| ⚠️ | accepted risk / limitation (explained) |

## A01 Broken Access Control

| Control | Where | Evidence |
|---|---|---|
| ✅ Every API route except login, password reset, health and config requires a Sanctum token (SEC-1) | `routes/api.php` | `AuthTest::SEC-1` |
| ✅ Policies for every model: course data needs enrolment, lecturer actions need lecturer enrolment, chats need participation (SEC-2) | `app/Policies/*` | `CourseAccessTest`, `SubmissionTest`, `QuizTest`, `MessagingTest`, `GradebookTest` |
| ✅ Students cannot grade, see the gradebook or other students' attempts; outsiders cannot open a course (E2E-4) | policies | `SubmissionTest`, `QuizTest`, `CourseAccessTest` |
| ✅ Files are downloaded only through the policy check, then a 5-minute presigned URL (SEC-4) | `AttachmentController`, `AttachmentPolicy` | `AttachmentTest` |
| ✅ Broadcast channel authorisation (SEC-3) incl. channel rotation when a member is removed | `routes/channels.php` | `ChannelAuthTest` |
| ✅ Attachments can only be linked by their uploader (no IDOR on `attachment_id`) | `AttachmentService::assertOwned` | `MaterialTest` |
| ✅ Admin endpoints behind `role:admin`; admins cannot demote/deactivate themselves | `EnsureRole`, `UserPolicy` | `AdminUsersTest` |
| ✅ Students only receive released grades and quiz answer keys once no retake is possible | `SubmissionResource`, `AttemptResource`, `SyncController` | `GradebookTest`, `QuizTest`, `SyncTest` |
| ✅ Horizon dashboard only for the host machine; Traefik dashboard and MinIO console bound to localhost | `HorizonServiceProvider`, `render-traefik.cjs`, `ecosystem.config.cjs` | `SecurityTest` |
| ✅ CORS limited to the web app origin (SEC-10) | `config/cors.php` | `SecurityTest` |

## A02 Cryptographic Failures

| Control | Where | Evidence |
|---|---|---|
| ✅ Message bodies encrypted at rest with the app key (AES-256-CBC + MAC, Eloquent `encrypted` cast) (SEC-6) | `Message::casts` | `MessagingTest::SEC-6` (raw column ≠ plaintext) |
| ☑️ HTTPS everywhere through Traefik (mkcert certificate), HTTP → HTTPS redirect, HSTS 1 year | `infra/traefik/*` | `curl -I http://localhost:8080` → 301; HSTS header |
| ✅ Passwords hashed with bcrypt; API tokens stored hashed (Sanctum) | Laravel defaults | — |
| ☑️ Files private in MinIO; presigned URLs expire after 5 minutes and are signed for the request host | `FileUrlSigner` | `AttachmentTest` |
| ⚠️ The bearer token is kept in IndexedDB (SEC-11) so the service worker can use it; an XSS could read it. Mitigated by the strict CSP (no inline script) and plain-text rendering | `features/auth/store.ts` | D54 in `docs/decisions.md` |

## A03 Injection

| Control | Where | Evidence |
|---|---|---|
| ✅ Eloquent / query builder with bindings everywhere; LIKE wildcards escaped | controllers | `SecurityTest::SEC-8` (`' OR 1=1 --`, `%`) |
| ✅ Validation with rules / Form Requests on every write (SEC-8) | `app/Http/Requests/*`, controllers | validation tests across the suite |
| ✅ User text rendered as text (React escaping, no `dangerouslySetInnerHTML` anywhere) | frontend | `grep -r dangerouslySetInnerHTML src` → none |
| ✅ CSV export neutralises spreadsheet formulas (`=`, `+`, `-`, `@`) | `GradebookController::export` | `SecurityTest` |
| ✅ Uploads: allow-list by extension **and** detected MIME (libmagic), 20 MB, random object names, SHA-256; no HTML/SVG/JS (SEC-5) | `AttachmentService` | `AttachmentTest`, `SecurityTest` |

## A04 Insecure Design

| Control | Evidence |
|---|---|
| ✅ Idempotent writes (`client_uuid`) so retries cannot duplicate messages/submissions | `MessagingTest`, `SubmissionTest`, `SyncTest::NFR-6` |
| ✅ Server time decides lateness; the client time is only audited | `SubmissionTest` |
| ✅ Quiz deadlines enforced server-side; answers after the deadline rejected | `QuizTest` |
| ✅ A graded submission cannot be overwritten by a resubmission | `SubmissionTest` |
| ✅ Optimistic concurrency (409) for lecturer edits | `MaterialTest` |

## A05 Security Misconfiguration

| Control | Evidence |
|---|---|
| ✅ `APP_DEBUG=false` by default; JSON errors carry only `{message, errors?}` | `SecurityTest` (no `trace`) |
| ✅ Security headers on API responses (nosniff, frame options, referrer policy, `default-src 'none'`, `no-store`) and via Traefik (HSTS) | `SecurityTest`, `routes.yml` |
| ☑️ Web CSP: `script-src 'self'` (no inline), `object-src 'none'`, `frame-ancestors 'self'`, `connect-src 'self' wss:` | `infra/traefik/dynamic/routes.yml`; no CSP violations in the browser console during E2E runs |
| ☑️ Scribe API docs are static files (no live route, no response calls) | `config/scribe.php` |
| ☑️ Admin consoles not exposed on the LAN by default (Traefik dashboard, MinIO console, Horizon) | `.env.example` |
| ☑️ MySQL bound to localhost by default; root only via the Unix socket | `infra/mysql/*.cnf` |

## A06 Vulnerable and Outdated Components

| Check | Result |
|---|---|
| ✅ `composer audit` | no advisories (see `docs/testing/results/composer-audit.txt`) |
| ✅ `npm audit` | 0 vulnerabilities after overriding `compression` to 1.8.2 (pulled in by `serve`) |
| ☑️ Laravel 13, PHP 8.4, MySQL 8.4 LTS, Traefik 3.7, Redis 7+, Node 22 | current supported versions |

## A07 Identification and Authentication Failures

| Control | Evidence |
|---|---|
| ✅ Failed logins: 5/min per email+IP (AUTH-1), 20/h per account per network, 30/min per IP; accounts under attack are slowed, not locked (known networks exempt) | `AuthTest` |
| ✅ Tokens expire after 7 days (SEC-11); logout / logout-all revoke; password change revokes other tokens; admin password reset revokes all | `AuthTest`, `ProfileTest`, `AdminUsersTest` |
| ✅ Deactivated users cannot sign in and lose every token (AUTH-6) | `AuthTest`, `AdminUsersTest` |
| ✅ Password reset answers identically for unknown e-mails (no enumeration); tokens are single use | `AuthTest::AUTH-3` |
| ✅ `X-Forwarded-For` trusted only from the gateway, so rate limits cannot be bypassed by spoofing | `AuthTest` |

## A08 Software and Data Integrity Failures

| Control | Evidence |
|---|---|
| ☑️ Front-end assets are built locally from locked dependencies (`package-lock.json`, `composer.lock`); the service worker precache uses content hashes |
| ☑️ Uploaded files carry a SHA-256 recorded at upload |
| ✅ Web Push subscription endpoints restricted to known push services (no SSRF) | `NotificationTest` |

## A09 Security Logging and Monitoring Failures

| Control | Evidence |
|---|---|
| ✅ Audit log (SEC-9): login success/failure, role changes, password resets, deactivation, enrolment changes, grade changes, grade release, submissions (client time) | `AuthTest`, `AdminUsersTest`, `AdminCoursesTest`, `GradebookTest`, `SubmissionTest` |
| ☑️ Admin audit log page with filters (action, actor, date) | `features/admin/AuditLogPage.tsx` |
| ☑️ Every request has an `X-Request-Id` from the client and `X-Served-By` from the instance | `lib/api.ts`, `AddServedBy` |

## A10 Server-Side Request Forgery

| Control | Evidence |
|---|---|
| ✅ Push endpoints allow-listed (HTTPS, vendor hosts, no ports/credentials), no redirects | `NotificationTest` |
| ☑️ The only other outgoing calls go to fixed, configured internal services (Reverb HTTP API, MinIO, Traefik API) |

## Dynamic scan (OWASP ZAP)

See `docs/testing/results/zap-baseline.md` (generated by `make zap` when ZAP is installed). If ZAP is
not installed, the report says so; the manual review above still applies.
