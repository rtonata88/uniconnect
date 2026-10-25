# Deployment (native, no Docker)

Every service runs as a native process. PM2 starts and supervises all of them from
`ecosystem.config.cjs`; Redis runs as a normal OS service. The same steps work on **Ubuntu 24.04** and
**macOS** (Apple Silicon or Intel).

- [1. Prerequisites](#1-prerequisites)
- [2. First-time setup](#2-first-time-setup)
- [3. Configuration (`backend/.env`)](#3-configuration-backendenv)
- [4. Running](#4-running)
- [5. HTTPS certificates (mkcert)](#5-https-certificates-mkcert)
- [6. MySQL replication](#6-mysql-replication)
- [7. Scaling and failover](#7-scaling-and-failover)
- [8. Multi-machine](#multi-machine)
- [9. Web Push (VAPID)](#9-web-push-vapid)
- [10. Installing the PWA on phones](#10-installing-the-pwa-on-phones)
- [11. Troubleshooting](#11-troubleshooting)

## 1. Prerequisites

| Software | Version | Notes |
|---|---|---|
| PHP | 8.4 with `pdo_mysql`, `redis`, `pcntl`, `intl`, `zip`, `mbstring`, `bcmath`, `gd` | CLI for artisan, Reverb, Horizon, tests. The API runs inside FrankenPHP (downloaded by Octane, embeds its own PHP). |
| Composer | 2.x | |
| Node.js | 22 | + `npm i -g pm2` |
| MySQL | 8.4 LTS (`mysqld` binary) | UniConnect runs its own primary + replica datadirs; an existing MySQL/MariaDB can stay installed |
| Redis | 7+ | OS service on 6379 |
| MinIO + `mc` | current | see the Apple M5 note below |
| Traefik | v3 | |
| mkcert | 1.4+ | local CA for HTTPS |
| k6 | 0.50+ | only for `make loadtest` |
| Java 17 + OWASP ZAP | optional | only for `make zap` |

Install them with the scripts:

```bash
./scripts/install-deps-ubuntu.sh     # apt + vendor repositories (uses sudo)
./scripts/install-deps-macos.sh      # Homebrew
./scripts/check-prereqs.sh           # verifies versions, extensions and free ports
```

**macOS notes**

- `mysql@8.4` is keg-only; set `MYSQL_BIN_DIR=/opt/homebrew/opt/mysql@8.4/bin` in `backend/.env`.
- With Laravel Herd, set `PHP_BIN=php84` (or switch Herd to 8.4).
- Apple **M5** CPUs: the Homebrew MinIO build crashes at start (`go-m1cpu`); MinIO no longer publishes
  binaries. Build both tools with `./scripts/build-minio-from-source.sh` (needs `brew install go`) and set
  `MINIO_BIN_DIR=$HOME/.local/bin` (decision D11).

**Ubuntu notes**

- The script disables the distribution's `mysql` service so port 3306 is free, and removes the AppArmor
  profile that blocks datadirs outside `/var/lib/mysql`.
- Ports 80/443 need privileges: the script grants `cap_net_bind_service` to the Traefik binary.

## 2. First-time setup

```bash
git clone <repo> uniconnect && cd uniconnect
make setup          # check prerequisites → composer/npm install → .env + keys → mkcert certificate
                    # → MySQL primary + replica + replication → MinIO bucket → migrations → frontend build
mkcert -install     # once: trust the local CA (asks for your password)
make up             # pm2 start ecosystem.config.cjs
make seed           # demo data (PRD §15)
```

Open `https://localhost` (or your `APP_URL`).

`make setup` is idempotent; every step can also be run alone: `make deps env certs db bucket migrate build`.

## 3. Configuration (`backend/.env`)

`backend/.env` is the single source of truth: Laravel, PM2, the Makefile, the scripts and the Traefik
config all read it (decision D1). `make env` creates it from `.env.example` and fills in `APP_KEY`, the
Reverb key/secret and the VAPID key pair.

| Variable | Default | Purpose |
|---|---|---|
| `APP_URL` | `https://localhost` | public origin (gateway); CORS, reset links |
| `APP_ADVERTISE_HOST` | `127.0.0.1` | address other machines use to reach instances on this one (set the LAN IP for multi-machine) |
| `PHP_BIN` | `php` | PHP 8.4 CLI binary |
| `GATEWAY_HTTP_PORT` / `GATEWAY_HTTPS_PORT` | 80 / 443 | Traefik entrypoints |
| `GATEWAY_DASHBOARD_PORT` / `_BIND` | 8090 / 127.0.0.1 | Traefik dashboard + API |
| `GATEWAY_INTERNAL_PORT` / `_BIND` | 8092 / 127.0.0.1 | API → Reverb publishing |
| `API_BASE_PORT`, `API_INSTANCES` | 8001, 2 | API port = base + PM2 instance id |
| `REVERB_BASE_PORT`, `REVERB_INSTANCES` | 8101, 2 | Reverb port = base + instance id |
| `WEB_PORT` | 5173 | static PWA server |
| `DB_HOST` / `DB_PORT` | 127.0.0.1 / 3306 | MySQL primary (writes) |
| `DB_REPLICA_HOST` / `DB_REPLICA_PORT` | 127.0.0.1 / 3307 | replica (reads) |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_REPLICATION_PASSWORD` | | created by `setup-replication.sh` |
| `DB_BIND_ADDRESS` | 127.0.0.1 | `0.0.0.0` lets API instances on other machines connect |
| `MYSQL_BIN_DIR`, `MINIO_BIN_DIR` | empty | directories of `mysqld` / `minio`+`mc` if not on PATH |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_REGISTRY_DB` | 127.0.0.1 / 6379 / 0 | Redis; registry + Traefik KV keys are unprefixed in that DB |
| `MINIO_PORT` / `MINIO_CONSOLE_PORT` / `MINIO_CONSOLE_BIND` | 9000 / 9001 / 127.0.0.1 | MinIO |
| `AWS_*` | | S3 credentials, bucket `uc-private`, endpoint `http://127.0.0.1:9000` |
| `REVERB_APP_ID/KEY/SECRET`, `REVERB_SCALING_ENABLED`, `REVERB_SCALING_CHANNEL` | | Reverb app + Redis pub/sub scaling |
| `VAPID_SUBJECT/PUBLIC_KEY/PRIVATE_KEY`, `PUSH_ALLOWED_HOSTS` | | Web Push |
| `TRUSTED_PROXIES` | 127.0.0.1,::1 | hosts allowed to set `X-Forwarded-*` (add the gateway's LAN IP on other machines) |
| `HORIZON_ALLOWED_IPS` | 127.0.0.1,::1 | who may open `/horizon` |
| `CORS_ALLOWED_ORIGINS` | empty | extra origins (e.g. `https://192.168.1.20`) |
| `CERT_EXTRA_HOSTS` | empty | extra names/IPs for the TLS certificate |
| `APP_DEBUG` | false | keep false (no stack traces in API errors) |
| `XDEBUG_EXTENSION` | empty | coverage driver for `make test` |

If a default port is taken on your machine, `check-prereqs.sh` says so; change it in `backend/.env` and
run `make down && make up`.

## 4. Running

| Command | What it does |
|---|---|
| `make up` | build the frontend if needed, `pm2 start ecosystem.config.cjs` |
| `make down` | `pm2 delete ecosystem.config.cjs` (only UniConnect's processes, D12) |
| `make restart` | restart everything (after changing `.env` or backend code — Octane keeps code in memory) |
| `make status` | `pm2 ls` + health + the System Status summary |
| `make logs` | `pm2 logs` (files in `storage/logs/<process>.{out,err}.log`) |
| `make seed` | fresh demo data (`migrate:fresh --seed`) |
| `make build` | rebuild the PWA (`frontend/dist`, served by the `web` process; no restart needed) |

PM2 processes: `mysql-primary`, `mysql-replica`, `minio`, `traefik`, `api` ×2, `reverb` ×2, `registry`,
`worker`, `scheduler`, `web`. To start them at boot: `pm2 startup && pm2 save`.

URLs:

| URL | |
|---|---|
| `https://localhost` | the app |
| `https://localhost/api/health` | health (`X-Served-By`) |
| `http://localhost:8090/dashboard/` | Traefik dashboard (services discovered from Redis) |
| `https://localhost/horizon` | Horizon (from the host machine) |
| `http://localhost:9001` | MinIO console |
| `docs/api/index.html` | API reference (Scribe, regenerate with `make docs`) |

## 5. HTTPS certificates (mkcert)

`make certs` (part of setup) creates `infra/traefik/certs/localhost.pem` for `localhost`, `127.0.0.1`,
`::1`, the machine's hostname, its LAN IP and `CERT_EXTRA_HOSTS`. Run `mkcert -install` once so your
browsers trust it. Regenerate after the LAN IP changes: `FORCE=1 make certs` (Traefik reloads the
certificate automatically).

HTTPS is required for service workers, Web Push and installation on phones.

## 6. MySQL replication

`make db` runs `infra/mysql/setup-replication.sh`, which:

1. renders `infra/mysql/primary.cnf` and `replica.cnf` into `storage/mysql/` (ports, bind address, datadirs);
2. initialises both datadirs (`mysqld --initialize-insecure`, root via Unix socket only);
3. starts both under PM2 (`mysql-primary`, `mysql-replica`);
4. creates the replication user, the app database and user (`REPLICATION CLIENT` for monitoring);
5. points the replica at the primary with GTID auto-positioning and starts replication;
6. waits until the replica has caught up.

Check it: `make status` or the System Status page (IO/SQL threads, `Seconds_Behind_Source`, measured lag).
Manual check:
```bash
mysql --socket=storage/mysql/replica/mysqld.sock -uroot -e 'SHOW REPLICA STATUS\G' | grep -E 'Running|Behind'
```
Put the replica on a second machine by running `setup-replication.sh` there with `DB_PORT` pointing at
the primary (bind the primary to the LAN with `DB_BIND_ADDRESS=0.0.0.0`) and setting `DB_REPLICA_HOST`.

## 7. Scaling and failover

```bash
make scale API=3 REVERB=3   # pm2 scale api 3 && pm2 scale reverb 3
make kill-api               # pm2 stop on one API instance (failover demo)
pm2 start <id>              # bring it back
```

New instances register in Redis within a few seconds; `registry:sync` adds them to Traefik and the
System Status page shows them — no configuration change. A stopped instance deletes its key on SIGTERM,
and Traefik's retry middleware covers requests in flight (decisions D7, D8).

## Multi-machine

Run extra API instances on a second computer in the same network:

**Machine 1** (`192.168.1.10`, runs everything else):
```dotenv
APP_ADVERTISE_HOST=192.168.1.10
DB_BIND_ADDRESS=0.0.0.0           # then: make db (re-renders the cnf) && make restart
TRUSTED_PROXIES=127.0.0.1,::1
```
Allow the ports in the firewall: 3306/3307 (MySQL), 6379 (Redis, set `bind 0.0.0.0` and a password),
9000 (MinIO). Machine 2's instances must be reachable from machine 1's Traefik on 8001+.

**Machine 2** (`192.168.1.11`): install PHP 8.4, Composer and PM2, check out the repository,
`cd backend && composer install`, copy `backend/.env` from machine 1 and change:
```dotenv
APP_ADVERTISE_HOST=192.168.1.11
DB_HOST=192.168.1.10
DB_REPLICA_HOST=192.168.1.10
REDIS_HOST=192.168.1.10
AWS_ENDPOINT=http://192.168.1.10:9000
REVERB_HOST=192.168.1.10          # internal gateway entrypoint on machine 1 (GATEWAY_INTERNAL_BIND=0.0.0.0 there)
TRUSTED_PROXIES=192.168.1.10      # machine 1's Traefik forwards to us
```
then:
```bash
pm2 start ecosystem.config.cjs --only api
```
The instance heartbeats `registry:api:192.168.1.11:8001` into machine 1's Redis; within ~3 s Traefik on
machine 1 routes to it, `X-Served-By: 192.168.1.11:8001` appears in responses and the System Status page
lists it with machine 2's hostname.

## 9. Web Push (VAPID)

`make env` generates the key pair (`php artisan webpush:vapid`). To rotate: `php artisan webpush:vapid
--force` (existing subscriptions then stop working; users re-enable push in Profile). The public key is
served to clients by `GET /api/config`. The server only sends to known push services
(`PUSH_ALLOWED_HOSTS`, decision D45).

## 10. Installing the PWA on phones

Installation and push need HTTPS with a certificate the phone trusts.

1. Find the machine's LAN IP and make sure it is in the certificate: `CERT_EXTRA_HOSTS=192.168.1.10`,
   `FORCE=1 make certs`. Add `https://192.168.1.10` to `CORS_ALLOWED_ORIGINS` and set
   `APP_URL=https://192.168.1.10` if the phone is the main client. Port 443 must be reachable.
2. Install the mkcert root CA on the phone: copy `$(mkcert -CAROOT)/rootCA.pem` to it.
   - **Android:** Settings → Security → Encryption & credentials → Install a certificate → CA certificate.
   - **iOS/iPadOS:** AirDrop/e-mail the file, install the profile (Settings → Profile Downloaded), then
     enable it under Settings → General → About → Certificate Trust Settings.
3. Open `https://192.168.1.10` in **Chrome (Android)** or **Safari (iOS 16.4+)**.
4. Install:
   - Android/desktop Chrome: the **Install app** button in Profile (or the browser's install prompt).
   - iOS: Share → **Add to Home Screen** (the Profile page shows these steps).
5. Open the installed app, sign in, Profile → **Enable push notifications**. On iOS, push only works in
   the installed app (iOS 16.4+).

Alternatively expose the app with a publicly trusted certificate (e.g. a domain pointing to the LAN IP
with a Let's Encrypt DNS-01 certificate) and put it in `infra/traefik/certs/`.

## 11. Troubleshooting

| Symptom | Fix |
|---|---|
| `make up` but `https://localhost` refuses | `pm2 logs traefik`; port 443 in use? change `GATEWAY_HTTPS_PORT` |
| API instance never appears in Traefik | `pm2 logs api`: Octane failed to start? `redis-cli keys 'registry:*'` lists heartbeats; `pm2 logs registry` |
| Replica shows IO/SQL stopped | `mysql --socket=storage/mysql/replica/mysqld.sock -uroot -e 'SHOW REPLICA STATUS\G'` → `Last_Error`; re-run `make db` |
| WebSockets do not connect | `pm2 logs reverb`; the browser must trust the certificate (`mkcert -install`) |
| Push never arrives | the device must be subscribed (Profile), the app must be closed or in the background, iOS needs the installed app |
| Code changes have no effect | Octane keeps code in memory: `pm2 restart api` |
| Tests touch the wrong database | `tests/TestCase.php` refuses anything but sqlite / `*_testing` |
