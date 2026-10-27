# Deploying on Laravel Forge

This guide deploys UniConnect to **one Forge server**, using the services Forge manages: Nginx, MySQL,
Redis and Supervisor daemons. It is the single-server production setup. The local development stack
in [deployment.md](deployment.md) (Traefik, MySQL replica, MinIO, several replicas) is not used here.

| Local stack (PM2) | On Forge |
|---|---|
| Traefik gateway | **Nginx** (static PWA + proxy to the API and Reverb) |
| MySQL primary + replica | **Forge's MySQL** (one server; reads use the primary) |
| MinIO bucket | **Local disk**, `backend/storage/app/private`, served by signed `/api/files/...` URLs |
| PM2 processes | **Forge daemons** (Supervisor): API, Reverb, Horizon, scheduler |
| mkcert certificate | **Let's Encrypt** (Forge) |

The files used below are in [`infra/forge/`](../infra/forge):

| File | Goes into |
|---|---|
| [`env.production`](../infra/forge/env.production) | Site → Environment |
| [`deploy.sh`](../infra/forge/deploy.sh) | Site → Deployments → Deploy script |
| [`nginx-locations.conf`](../infra/forge/nginx-locations.conf) | Site → Nginx configuration |

Below, `uni.example.com` stands for your domain. Replace it everywhere.

## 1. Check the server

UniConnect needs **PHP 8.4**, **MySQL 8**, **Redis** and **Node.js 22+** on the server.

- **PHP 8.4:** in Forge, open the server's PHP settings and install 8.4 if it isn't there.
- **Node.js:** SSH in (`ssh forge@<server-ip>`) and run `node -v`. If it's older than 22, switch the
  server's Node version in Forge, or install Node 22 from NodeSource.
- **PHP extensions:** check that the CLI has everything:
  ```bash
  php8.4 -m | grep -iE 'pdo_mysql|redis|pcntl|intl|zip|mbstring|bcmath|gd'
  ```
  All eight should be listed. Install anything missing, e.g. `sudo apt-get install php8.4-redis`.

## 2. Create the database

Server → **Databases**: create a database (e.g. `uniconnect`) and a database user with a strong
password who has access to it. Note the three values for the Environment step.

## 3. Create the site

Server → **New site**:

| Field | Value |
|---|---|
| Domain | `uni.example.com` (point its DNS A record at the server first) |
| Project type | Laravel / PHP |
| PHP version | 8.4 |
| Root directory | leave the default (the repository root) |
| Web directory | leave the default; you'll set the Nginx root by hand in step 7 |
| Repository | `rtonata88/uniconnect`, branch `main` |
| Install Composer dependencies | **off** (`composer.json` is in `backend/`; the deploy script installs it) |
| Advanced settings → Zero-downtime deployments | **off** (it's **on by default**) |

> **Zero-downtime deployments must be turned off when you create the site.** Forge enables them by
> default, and they can't be turned off later. Forge's docs say not to use them with Octane, and
> uploaded files in `backend/storage` would not carry over between releases. If you see `releases/000000`
> in a deploy log, the site was created with them on: delete the site and create it again with the
> toggle off.

After the site is created, Forge fills in its own default deploy script. Replace it with UniConnect's
script in step 5 **before** the first deploy.

## 4. Environment

Site → **Environment**: replace the contents with [`infra/forge/env.production`](../infra/forge/env.production)
and fill in the values in `<angle brackets>`:

- `APP_URL`: `https://uni.example.com`
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`: from step 2
- `REVERB_APP_KEY`, `REVERB_APP_SECRET`: random strings (`openssl rand -hex 10` and `openssl rand -hex 20`)
- `VAPID_SUBJECT`, the `MAIL_*` settings (password-reset e-mails) and your IP in `HORIZON_ALLOWED_IPS`

Leave `APP_KEY` and the VAPID keys empty; step 6 generates them.

Forge saves this as `.env` in the repository root. The deploy script links `backend/.env` to it, so
the app, the daemons and `artisan` all read the same file.

## 5. Deploy script and first deploy

Site → **Deployments** → deploy script: replace it with [`infra/forge/deploy.sh`](../infra/forge/deploy.sh).

The script pulls the code and runs `composer install --no-dev`. On the first deploy it also downloads
FrankenPHP for Octane. Every deploy then runs the migrations, caches the routes, builds the PWA into
`frontend/dist` and tells the running API, Reverb and Horizon to reload.

Click **Deploy now**. The first deploy takes a few minutes (Composer, FrankenPHP, `npm ci`, build).
The reload commands at the end have nothing to reload yet; that's expected.

## 6. Generate the secrets (once)

SSH in and run:

```bash
cd ~/uni.example.com/backend
grep -q '^APP_KEY=base64' .env || php8.4 artisan key:generate --force
php8.4 artisan webpush:vapid --force
```

This writes `APP_KEY`, `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` into the `.env`, which the Forge
Environment page then shows. Don't change `APP_KEY` later: message bodies are encrypted with it.

## 7. HTTPS and Nginx

1. Site → **SSL** → Let's Encrypt → issue a certificate for `uni.example.com`. Do this first: Forge
   adds the `ssl_*` lines to the Nginx config.
2. Site → **Nginx configuration**, inside the `server { listen 443 ... }` block:
   - change `root` to `/home/forge/uni.example.com/frontend/dist;`
   - delete Forge's `location / { ... }`, `location ~ \.php$ { ... }` and
     `location ~ /\.(?!well-known).* { ... }` blocks;
   - paste the contents of [`infra/forge/nginx-locations.conf`](../infra/forge/nginx-locations.conf) in
     their place;
   - keep every `ssl_*`, `include forge-conf/...` and `listen` line.
3. Save. Forge tests the config and reloads Nginx.

Nginx now serves the PWA from `frontend/dist` and forwards three kinds of request:

- `/api/*` (including `/api/broadcasting/auth` and `/api/files/*`) and `/horizon` go to Octane on
  `127.0.0.1:8001`;
- `/app/*` and `/apps/*` (WebSockets) go to Reverb on `127.0.0.1:8101`.

## 8. Daemons

Server → **Daemons** (Background processes): add four. For each one, set:

- **Directory:** `/home/forge/uni.example.com`
- **User:** `forge`
- **Processes:** 1

| Name | Command | Stop wait (seconds) |
|---|---|---|
| API (Octane) | `bash scripts/run-api.sh` | 15 |
| Reverb | `bash scripts/run-reverb.sh` | 10 |
| Horizon | `bash scripts/run-with-heartbeat.sh worker php8.4 artisan horizon` | 20 |
| Scheduler | `bash scripts/run-with-heartbeat.sh scheduler php8.4 artisan schedule:work` | 10 |

These are the same scripts PM2 runs locally. Each one loads `backend/.env` and registers its process in
the Redis service registry, so the System Status page lists the instances.

Don't add a Forge **Scheduler** job for `schedule:run` as well; the Scheduler daemon already does
that job. Octane and Reverb listen on all interfaces, but Forge's firewall only opens ports 22, 80 and
443, so ports 8001 and 8101 stay private. Don't open them.

## 9. Data

**For a demo or presentation:** load the demo courses, chats and accounts. Every account's password is
`Password123!`, so change them or remove the accounts before real users join:

```bash
cd ~/uni.example.com/backend && php8.4 artisan db:seed --force
```

**For real use:** create just an administrator, then add lecturers, students and courses in the app
(Admin → Users / Courses):

```bash
cd ~/uni.example.com/backend && php8.4 artisan tinker --execute '
App\Models\User::forceCreate(["name" => "Admin", "email" => "you@example.com", "role" => "admin",
  "password" => "<a-strong-password>", "is_active" => true, "email_verified_at" => now()]);'
```

## 10. Check it

```bash
curl -sI https://uni.example.com/api/health | grep -iE '^HTTP|x-served-by'   # 200, X-Served-By: <host>:8001
```

Then in a browser:

1. Open `https://uni.example.com` and sign in.
2. As admin, open **System status**. Every check should be green: the database shows "no replica
   configured" and storage shows "files on local disk". The instances list shows api, reverb, worker
   and scheduler. Their "not routed yet" label is expected here, because Nginx, not Traefik, does the
   routing.
3. Open a chat in two browsers with two accounts and send a message. It should appear immediately
   (WebSockets through Nginx → Reverb).
4. Upload a file to a course or a chat and open it (signed `/api/files/...` URL).
5. Open `https://uni.example.com/horizon` from an IP listed in `HORIZON_ALLOWED_IPS`.

## Updating

Push to `main` and click **Deploy now**, or enable **Quick deploy** to deploy on every push. The
script reloads Octane, Reverb and Horizon itself; you don't need to restart the daemons by hand.

After you change the **Environment**, restart the four daemons (Daemons → Restart) so they read the new
values.

## Backups

- **Database:** Server → Backups (Forge database backups) to S3 or another provider.
- **Uploaded files:** `backend/storage/app/private`. Forge doesn't back this up; copy it off the server
  regularly, e.g. with `rsync` or `restic` from a cron job.

## Troubleshooting

| Symptom | Check |
|---|---|
| 502 Bad Gateway on `/api/...` | The API daemon isn't running: check its status and logs in Forge, then `backend/storage/logs/laravel-*.log`. On the first start, Octane needs `backend/frankenphp` from the deploy. |
| Page loads but sign-in fails with a network error | `APP_URL` doesn't match the domain, or Nginx doesn't have the `/api` location. |
| Chat messages only appear after a refresh | Reverb daemon status; the `/app/` location in Nginx; `REVERB_HOST=127.0.0.1` and `REVERB_PORT=8101` in the Environment. |
| Files fail with 403 | The link is older than 5 minutes (they expire), or the server clock is wrong. |
| Changes in the Environment have no effect | Restart the daemons; the deploy script doesn't cache config, so nothing else needs clearing. |
| Horizon returns 403 | Your IP isn't in `HORIZON_ALLOWED_IPS`. |
| Push notifications never arrive | The VAPID keys are missing (step 6), or the browser hasn't enabled push in Profile. |
