# UniConnect deploy script for Laravel Forge: paste into Site → Deployments → Deploy script.
# Assumes the repository is the site root ($FORGE_SITE_PATH) and PHP 8.4.
# Use a standard (in-place) deployment, not zero-downtime: uploaded files live in
# backend/storage/app/private and must survive deploys.
set -e

if [ -n "$FORGE_RELEASE_DIRECTORY" ]; then
    echo "This site uses zero-downtime deployments, which Forge does not support with Octane and"
    echo "which cannot be turned off. Recreate the site with Advanced settings → Zero-downtime"
    echo "deployments OFF (docs/forge.md, step 3)."
    exit 1
fi

cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH

# Forge keeps the site's Environment in $FORGE_SITE_PATH/.env; the app reads backend/.env
touch .env
if [ ! -L backend/.env ]; then
    [ -f backend/.env ] && mv backend/.env backend/.env.before-forge
    ln -s ../.env backend/.env
fi

# Backend
cd $FORGE_SITE_PATH/backend
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Forge's default Environment + what UniConnect needs (database uniconnect/forge, Redis, Reverb, keys)
bash $FORGE_SITE_PATH/infra/forge/prepare-env.sh $FORGE_SITE_PATH $FORGE_PHP

# FrankenPHP binary + worker file for Octane are not in git; download them once
if [ ! -f frankenphp ] || [ ! -f public/frankenphp-worker.php ]; then
    $FORGE_PHP artisan octane:install --server=frankenphp --no-interaction
fi

$FORGE_PHP artisan migrate --force
# No config:cache: each daemon gets its identity (INSTANCE_SERVICE, INSTANCE_PORT,
# REVERB_SERVER_PORT, OCTANE_STATE_FILE) from environment variables set by scripts/run-*.sh,
# and a cached config would give every process the same values.
$FORGE_PHP artisan config:clear
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan event:cache

# Frontend (PWA) -> frontend/dist, served directly by Nginx
cd $FORGE_SITE_PATH/frontend
npm ci --no-audit --no-fund
npm run build

# Pick up the new code in the long-running processes (Forge daemons restart them)
cd $FORGE_SITE_PATH/backend
OCTANE_STATE_FILE=storage/logs/octane-state-8001.json $FORGE_PHP artisan octane:reload || true
$FORGE_PHP artisan reverb:restart
$FORGE_PHP artisan horizon:terminate
