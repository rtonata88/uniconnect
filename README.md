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

Open **https://localhost**. The full walkthrough is below; port changes, phones and multi-machine are in
[docs/deployment.md](docs/deployment.md).

## Setup and running

Supported on **macOS** (Apple Silicon or Intel), **Ubuntu 24.04** and **Windows 10/11 through WSL 2**
(Ubuntu 24.04). Everything runs natively under PM2; there is no Docker.

These steps are for running UniConnect on your own machine. To put it on a server with Laravel Forge,
see [docs/forge.md](docs/forge.md).

### 1. Install the prerequisites

| Software | Version |
|---|---|
| PHP | 8.4 with `pdo_mysql`, `redis`, `pcntl`, `intl`, `zip`, `mbstring`, `bcmath`, `gd` |
| Composer | 2.x |
| Node.js + PM2 | 22+ (`npm i -g pm2`) |
| MySQL | 8.4 LTS (`mysqld` binary; UniConnect runs its own primary and replica) |
| Redis | 7+, running as an OS service on port 6379 |
| MinIO + `mc` | current |
| Traefik | v3 |
| mkcert | 1.4+ |
| k6, Java 17 + OWASP ZAP | optional, only for `make loadtest` / `make zap` |

The install scripts set all of this up:

**macOS** (needs [Homebrew](https://brew.sh)):

```bash
./scripts/install-deps-macos.sh
export PATH="$(brew --prefix)/opt/mysql@8.4/bin:$PATH"   # mysql@8.4 is keg-only (or set MYSQL_BIN_DIR, step 3)
brew services start redis
```

**Ubuntu** (uses `sudo`; also starts Redis, frees port 3306 and lets Traefik bind ports 80/443):

```bash
./scripts/install-deps-ubuntu.sh
```

**Windows**: the scripts need a Linux shell, so UniConnect runs inside WSL 2 and you use it from a
Windows browser.

1. In PowerShell **as Administrator**, install WSL with Ubuntu 24.04 and restart when asked:
   ```powershell
   wsl --install -d Ubuntu-24.04
   ```
2. Open the **Ubuntu** app and create your Linux user. Make sure systemd is on (the Ubuntu script uses
   it to start Redis). Check `/etc/wsl.conf`; if it doesn't contain the following, add it:
   ```ini
   [boot]
   systemd=true
   ```
   then run `wsl --shutdown` in PowerShell and reopen Ubuntu.
3. **Inside Ubuntu**, install the prerequisites. Clone into your Linux home directory (`~/`), not under
   `/mnt/c/...`: MySQL can't use datadirs on the Windows drive, and file access there is slow.
   ```bash
   sudo apt-get update && sudo apt-get install -y git make
   cd ~ && git clone git@github.com:rtonata88/uniconnect.git && cd uniconnect
   ./scripts/install-deps-ubuntu.sh
   ```
4. Ports 80 and 443 must be free **on Windows too**, because WSL forwards `localhost` to Windows. If IIS
   or another web server is using them, stop it or change `GATEWAY_HTTP_PORT` / `GATEWAY_HTTPS_PORT`
   (step 3).

Run every remaining command in this guide **inside the Ubuntu terminal**. Skip step 2 (Clone), because
you already cloned the repository.

Then trust the local certificate authority once (it asks for your password) and check everything:

```bash
mkcert -install
./scripts/check-prereqs.sh     # versions, PHP extensions and free ports
```

On **Windows**, `mkcert -install` only trusts the certificate inside WSL. To make your Windows browsers
trust it as well, copy the CA to Windows and import it from PowerShell (no Administrator needed):

```bash
cp "$(mkcert -CAROOT)/rootCA.pem" /mnt/c/Users/<your-windows-user>/Downloads/uniconnect-rootCA.crt
```
```powershell
certutil -addstore -user Root $HOME\Downloads\uniconnect-rootCA.crt
```

Restart the browser afterwards. Firefox uses its own certificate store: import the file under
Settings → Privacy & Security → Certificates → View Certificates → Authorities.

### 2. Clone the repository

```bash
git clone git@github.com:rtonata88/uniconnect.git && cd uniconnect
```

### 3. Configure (only if needed)

`make setup` creates `backend/.env` from `backend/.env.example` and generates the app key, Reverb
credentials and Web Push (VAPID) keys. The defaults work on a single machine. Edit `backend/.env` first
only if:

- a default port is taken (`check-prereqs.sh` warns): change `GATEWAY_HTTPS_PORT`, `DB_PORT`, etc.;
- your PHP 8.4 binary is not `php`, e.g. Laravel Herd: `PHP_BIN=php84`;
- `mysqld` is not on your `PATH`: `MYSQL_BIN_DIR=/opt/homebrew/opt/mysql@8.4/bin`;
- you are on an Apple **M5** Mac: the Homebrew MinIO crashes, so build it with
  `./scripts/build-minio-from-source.sh` (needs `brew install go`) and set `MINIO_BIN_DIR=$HOME/.local/bin`.

Outside a local demo, also change the default database passwords before setup (see [Database](#database)).

All variables are described in [docs/deployment.md § Configuration](docs/deployment.md#3-configuration-backendenv).

### 4. Set up

```bash
make setup
```

This checks the prerequisites, runs `composer install` and `npm install`, writes `backend/.env` and its
keys, creates the HTTPS certificate, initialises the MySQL primary and replica and starts replication,
creates the MinIO bucket, runs the migrations and builds the frontend. You can run it again safely, and
every step can be run on its own: `make deps env certs db bucket migrate build`.

### 5. Start the application and load demo data

```bash
make up       # starts every process under PM2
make seed     # fresh demo data (re-run any time to reset)
```

Open **https://localhost** and sign in with one of the [demo accounts](#demo-accounts).

### 6. Check that it is running

```bash
make status                               # PM2 processes, health and the System Status summary
curl -I https://localhost/api/health      # shows X-Served-By: <instance>
```

PM2 should list `mysql-primary`, `mysql-replica`, `minio`, `traefik`, `api` ×2, `reverb` ×2,
`registry`, `worker`, `scheduler` and `web`, all `online`.

### Day-to-day

| Task | Command |
|---|---|
| Stop everything | `make down` |
| Restart after changing `backend/.env` or backend code (Octane keeps code in memory) | `make restart` |
| Rebuild the PWA after frontend changes (no restart needed) | `make build` |
| Follow the logs | `make logs` (files in `storage/logs/`) |
| Start UniConnect when the machine boots | `pm2 startup && pm2 save` |
| Run the tests | `make test` |

Something not working? See [docs/deployment.md § Troubleshooting](docs/deployment.md#11-troubleshooting).

### Database

UniConnect doesn't use a MySQL server you already have. It runs its **own** two MySQL 8.4 instances,
started and supervised by PM2, with their data under `storage/mysql/`:

| Instance | PM2 name | Port | Role |
|---|---|---|---|
| Primary | `mysql-primary` | `DB_PORT` (3306) | all writes |
| Replica | `mysql-replica` | `DB_REPLICA_PORT` (3307) | reads; GTID replication from the primary |

Laravel sends writes to the primary and reads to the replica. `make setup` creates both instances, so
you normally don't need to do anything else. The rest of this section explains what happens and how
to work with the database.

**Credentials** come from `backend/.env`:

| Variable | Default | Used for |
|---|---|---|
| `DB_DATABASE` | `uniconnect` | app database (plus `uniconnect_testing`) |
| `DB_USERNAME` / `DB_PASSWORD` | `uniconnect` / `uniconnect-secret` | the app's MySQL user |
| `DB_REPLICATION_PASSWORD` | `repl-secret` | the `repl` user the replica connects with |

Change them in `backend/.env` **before** the database is first created. Running `make db` again
updates the app user's password, but not the replication password. To change that one later, reset
the database (below). The MySQL `root` user has no password and only accepts local Unix-socket
connections.

**Creating the database step by step** (this is what `make setup` does):

```bash
make db        # infra/mysql/setup-replication.sh:
               #  1. renders storage/mysql/primary.cnf + replica.cnf from infra/mysql/
               #  2. initialises both datadirs (first run only)
               #  3. starts mysql-primary and mysql-replica under PM2
               #  4. creates the databases, the app user and the repl user
               #  5. points the replica at the primary (GTID auto-position) and starts replication
               #  6. waits until the replica has caught up
make migrate   # creates the tables (php artisan migrate)
make seed      # drops all tables, re-migrates and loads the demo data
```

`make db` is safe to run again; it skips anything that already exists. If `mysqld` isn't on your PATH,
set `MYSQL_BIN_DIR` (step 3).

**Connecting** with a client (MySQL Workbench, TablePlus, DBeaver, or the CLI):

```bash
mysql -h 127.0.0.1 -P 3306 -u uniconnect -p uniconnect                 # primary, password = DB_PASSWORD
mysql -h 127.0.0.1 -P 3307 -u uniconnect -p uniconnect                 # replica (read-only use)
mysql --socket=storage/mysql/primary/mysqld.sock -uroot                # root, from the project directory
```

On Windows, GUI clients running on Windows can connect to `127.0.0.1:3306` directly, because WSL
forwards the port.

**Checking replication**: run `make status`, open the System Status page in the app as admin, or:

```bash
mysql --socket=storage/mysql/replica/mysqld.sock -uroot -e 'SHOW REPLICA STATUS\G' \
  | grep -E 'Replica_IO_Running|Replica_SQL_Running:|Seconds_Behind_Source|Last_Error'
```

Both `Running` values should be `Yes`. If they aren't, `Last_Error` explains why; running `make db`
again usually fixes it.

**Resetting**:

```bash
make seed                  # keep the instances, wipe and reload the demo data

make down                  # full reset: stop everything …
rm -rf storage/mysql       # … delete both datadirs (all data is lost) …
make db migrate seed       # … recreate the instances, the tables and the demo data
make up
```

**Tests** don't touch this database: Pest uses an in-memory SQLite database (`backend/phpunit.xml`),
and `tests/TestCase.php` refuses to run against anything but SQLite or a `*_testing` database.

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
infra/        traefik/ (static + dynamic config, certs) · mysql/ (primary, replica, setup) · minio/ · forge/ (Laravel Forge)
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
| [docs/forge.md](docs/forge.md) | production deployment on one Laravel Forge server (Nginx, Forge MySQL/Redis, daemons) |
| [docs/user-guide.md](docs/user-guide.md) | how to use UniConnect as a student, lecturer or admin |
| [docs/testing/](docs/testing/) | [test plan](docs/testing/test-plan.md), [security checklist](docs/testing/security-checklist.md), [**TEST_REPORT.md**](docs/testing/TEST_REPORT.md), raw results |
| [docs/decisions.md](docs/decisions.md) | design decisions and trade-offs |
| [docs/demo-script.md](docs/demo-script.md) | the 10-minute presentation demo |

## Technology

React 19, TypeScript, Vite 8, Tailwind CSS 4, shadcn/ui, TanStack Query/Table, Zustand, Dexie, Workbox
(vite-plugin-pwa), Laravel Echo + pusher-js · Laravel 13 (PHP 8.4), Octane + FrankenPHP, Sanctum, Reverb,
Horizon, Scribe, laravel-notification-channels/webpush · MySQL 8.4 LTS (GTID replication), Redis, MinIO,
Traefik v3 (Redis KV + file providers), PM2 · Pest, Vitest, Playwright, k6, Lighthouse, OWASP ZAP.
