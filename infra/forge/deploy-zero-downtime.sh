# UniConnect deploy script for Laravel Forge sites with ZERO-DOWNTIME deployments (Forge's default).
# Paste into Site → Deployments → Deploy script. For a standard (in-place) site use deploy.sh instead.
#
# Each deploy is a fresh checkout in releases/<n>; Forge points `current` at it at the end. Things that must outlive a release are kept in $FORGE_SITE_ROOT/shared:
#   backend-storage/  uploaded files, logs, Octane state (symlinked to backend/storage)
#   frankenphp        the Octane server binary (downloaded once, ~100 MB)
# Forge already shares .env (release/.env -> $FORGE_SITE_ROOT/.env).
# Needs the release-watch daemon (docs/forge.md, step 7) to move running daemons to new releases.

$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY
set -e
SHARED=$FORGE_SITE_ROOT/shared
mkdir -p $SHARED

# .env: Forge's shared Environment; the app reads backend/.env
if [ ! -e .env ]; then
    touch $FORGE_SITE_ROOT/.env
    ln -s $FORGE_SITE_ROOT/.env .env
fi
ln -sfn ../.env backend/.env

# backend/storage: shared, so uploads survive releases (first deploy seeds it from the repository)
if [ ! -d $SHARED/backend-storage ]; then
    cp -R backend/storage $SHARED/backend-storage
fi
rm -rf backend/storage
ln -s $SHARED/backend-storage backend/storage

# Backend
cd $FORGE_RELEASE_DIRECTORY/backend
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Forge's default Environment + what UniConnect needs (database uniconnect/forge, Redis, Reverb, keys)
bash $FORGE_RELEASE_DIRECTORY/infra/forge/prepare-env.sh $FORGE_RELEASE_DIRECTORY $FORGE_PHP $(basename $FORGE_SITE_ROOT)

# FrankenPHP for Octane: download once into shared/, then link it into every release
if [ ! -f $SHARED/frankenphp ]; then
    $FORGE_PHP artisan octane:install --server=frankenphp --no-interaction
    mv frankenphp $SHARED/frankenphp
fi
ln -sfn $SHARED/frankenphp frankenphp
[ -f public/frankenphp-worker.php ] || cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/

$FORGE_PHP artisan migrate --force
# No config:cache: each daemon gets its identity (INSTANCE_SERVICE, INSTANCE_PORT,
# REVERB_SERVER_PORT, OCTANE_STATE_FILE) from environment variables set by scripts/run-*.sh,
# and a cached config would give every process the same values.
$FORGE_PHP artisan config:clear
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan event:cache

# Frontend (PWA) -> frontend/dist, served by Nginx from current/frontend/dist
cd $FORGE_RELEASE_DIRECTORY/frontend
npm ci --no-audit --no-fund
npm run build

# Keep the two Forge macros below as the last lines, and never write a macro name in a comment:
# Forge expands macros wherever they appear. The release-watch daemon (infra/forge/release-watch.sh) moves
# the running daemons onto the new release once `current` switches.
$ACTIVATE_RELEASE()

$RESTART_QUEUES()
