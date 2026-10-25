# Demo script (~10 minutes)

**Before the demo**

```bash
make up && make seed            # fresh demo data
make status                     # everything green
```
- Desktop: Chrome window A (lecturer), Chrome window B in a second profile (third user), a terminal, the
  Traefik dashboard tab (`http://localhost:8090/dashboard/`), DevTools open on the Network tab.
- Phone: UniConnect installed from `https://<LAN IP>` (see [deployment.md §10](deployment.md#10-installing-the-pwa-on-phones)),
  signed in as **student1**, push notifications enabled, then the app closed.
- Accounts (password `Password123!`): lecturer `mziezo@uniconnect.test`, students `student1…3@uniconnect.test`,
  admin `admin@uniconnect.test`.

---

### 1. Architecture (1 min)
- Show the deployment diagram in [architecture.md](architecture.md#1-deployment): gateway, API and Reverb
  replicas, primary/replica database, Redis, MinIO, workers — all native processes.
- Terminal: `pm2 ls` → two `api`, two `reverb`, `registry`, `worker`, `scheduler`, `traefik`, `minio`,
  `web`, `mysql-primary`, `mysql-replica`.
- Traefik dashboard → HTTP → Services: `api@redis` and `reverb@redis` list the servers that
  `registry:sync` wrote into Redis (service discovery), each health-checked.

### 2. Log in, load balancing (1 min)
- Window A: log in as the lecturer. Phone: open the installed app (student1).
- DevTools → Network → any `/api/` request → Response headers: `X-Served-By: 127.0.0.1:8001`, next request
  `…:8002` — requests alternate between replicas.

### 3. New assignment → toast and push (1 min)
- Lecturer: Courses → DMS901 → Assignments → **New assignment** "Demo lab", due tomorrow → Save.
- Phone (app closed): a push notification "New assignment in DMS901" arrives; tapping it opens the
  assignment screen (MOB-7). With the app open, a toast appears instead and the bell count goes up.

### 4. Chat: typing, ticks, file (1.5 min)
- Phone: Courses → DMS901 → People → message Dr. Ziezo. Lecturer opens the chat (Chats).
- Student types: the lecturer sees "Anna is typing…". Send: ✓ then ✓✓ and **blue ✓✓** because the
  lecturer has the chat open.
- Lecturer sends a PDF with 📎: progress bar, download card with name and size on the phone.

### 5. Course group chat + message info (1 min)
- Window B: log in as student2, open DMS901 → Chat. Lecturer posts in the course chat.
- When student2 and the phone have read it, the lecturer opens ⋮ → **Message info**: who received it
  and who read it, with times.

### 6. Offline (2 min)
- Phone: switch on **airplane mode**. The status pill says *Offline – changes will sync*, the yellow bar
  says *showing saved data*.
- Browse DMS901 (overview, announcements, assignments) — all from the device. Open a material that was
  marked **Offline** — it opens without network.
- Send two chat messages (🕓 clock icons) and submit the "Demo lab" assignment (*Waiting to send*).
- Switch airplane mode off: the pill shows *Syncing…*, messages turn ✓/✓✓, the submission shows as
  submitted. The lecturer receives the two messages in order, once each.

### 7. Grade and release (1 min)
- Lecturer: Demo lab → row "Anna Nghipandulwa" (student1) → score + feedback → Save grade.
- Course → Grades → **Release** on the Demo lab column. Phone: notification "Grade released in DMS901";
  the assignment shows the grade and feedback.

### 8. Scaling and failover (1.5 min)
- Admin (window B, log out and in as admin) → **System status**: 2 API, 2 Reverb instances, replica lag,
  queues, sockets.
- Terminal: `make scale API=3` → within seconds a third API instance appears on the System Status page and
  in the Traefik dashboard; "Load balancing" shows requests reaching `:8003`.
- Terminal: `make kill-api` → the instance disappears; the app keeps working (keep chatting on the
  phone). `pm2 start <id>` to bring it back.
- Optional: on a second computer `pm2 start ecosystem.config.cjs --only api` (see
  [deployment.md](deployment.md#multi-machine)) → its LAN IP shows up as a new API instance.

### 9. Test results (0.5 min)
- Open [testing/TEST_REPORT.md](testing/TEST_REPORT.md): test counts and coverage, the NFR table (latency,
  sockets, failover, scaling, Lighthouse) and the traceability table — all measured.
- `make verify-realtime`: two users on different Reverb instances, 5/5 messages, latency in ms.

### 10. Security (0.5 min)
- As student7 (DMS901 only) paste the URL of SE902 (`/courses/2`) → **Access denied** (API 403).
- Terminal — message bodies are encrypted at rest:
  ```bash
  mysql --socket=storage/mysql/primary/mysqld.sock -uroot uniconnect -e 'SELECT id, LEFT(body,60) FROM messages ORDER BY id DESC LIMIT 3'
  ```
  shows `eyJpdiI6…` ciphertext, while the app shows the text.
- Admin → **Audit log**: the logins, the grade change and the grade release from this demo.
