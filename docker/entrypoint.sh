#!/bin/sh
# TryPost — container entrypoint. Idempotent first-run setup, then exec supervisord.

set -e

cd /var/www/html

TARGET="${TRYPOST_TARGET:-dev}"

# One-off commands from `docker compose run app ...` must bypass the long-lived
# application bootstrap and execute exactly as requested.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

if [ "${TARGET}" = "production" ] && [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] APP_KEY is required in production" >&2
    exit 1
fi

# 1) Bootstrap .env from the Docker template on first dev boot. The bind-mount
#    in dev hides /var/www/html/.env.docker.example, so prefer docker/ first.
if [ "${TRYPOST_DOCKER_BOOTSTRAP:-0}" = "1" ] && [ ! -f .env ]; then
    if [ -f docker/.env.docker.example ]; then
        echo "[entrypoint] seeding .env from docker/.env.docker.example"
        cp docker/.env.docker.example .env
    elif [ -f .env.docker.example ]; then
        echo "[entrypoint] seeding .env from .env.docker.example"
        cp .env.docker.example .env
    fi
    # Hand the seeded .env over to the host user so they can edit it.
    chown "${UID:-1000}:${GID:-1000}" .env 2>/dev/null || true
fi

# 2) Skip-bootstrap escape hatch for advanced users.
if [ "${TRYPOST_SKIP_BOOTSTRAP:-0}" = "1" ]; then
    echo "[entrypoint] TRYPOST_SKIP_BOOTSTRAP=1 — exec'ing supervisord without setup"
    exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
fi

# 3) Re-install composer deps if vendor was wiped (down -v in dev).
if [ "${TARGET}" = "dev" ] && [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ missing — running composer install"
    composer install --no-interaction --prefer-dist
fi

# 4) Re-install node_modules if empty/wiped (dev only; vite needs them).
#    Anonymous volumes pre-create the dir, so check for npm's lockfile instead.
if [ "${TARGET}" = "dev" ] && [ ! -f node_modules/.package-lock.json ]; then
    echo "[entrypoint] node_modules/ empty — running npm ci"
    npm ci --no-audit --no-fund
fi

# 5) APP_KEY — generate on first boot if blank.
if [ -f .env ] && ! grep -qE '^APP_KEY=base64:' .env; then
    echo "[entrypoint] generating APP_KEY"
    php artisan key:generate --force
fi

# 6) Wait for Postgres to be reachable.
DB_HOST_VALUE="${DB_HOST:-pgsql}"
DB_PORT_VALUE="${DB_PORT:-5432}"
DB_USER_VALUE="${DB_USERNAME:-postgres}"
DB_NAME_VALUE="${DB_DATABASE:-trypost}"

echo "[entrypoint] waiting for postgres at ${DB_HOST_VALUE}:${DB_PORT_VALUE}"
WAIT_ATTEMPTS=0
until pg_isready -h "${DB_HOST_VALUE}" -p "${DB_PORT_VALUE}" -U "${DB_USER_VALUE}" -d "${DB_NAME_VALUE}" >/dev/null 2>&1; do
    WAIT_ATTEMPTS=$((WAIT_ATTEMPTS + 1))
    if [ "${WAIT_ATTEMPTS}" -gt 60 ]; then
        echo "[entrypoint] postgres not reachable after 60s — continuing anyway"
        break
    fi
    sleep 1
done

# 7) Run migrations (graceful: succeeds even when nothing to migrate).
echo "[entrypoint] running migrations"
php artisan migrate --force

# 8) storage:link if missing.
if [ ! -L public/storage ]; then
    echo "[entrypoint] linking storage"
    php artisan storage:link --force || true
fi

# 9) Passport keys. Prefer PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY from
# the environment (required for durable / multi-node deploys — storage/oauth-*
# is not on a persisted volume in compose.prod.yaml). Fall back to generating
# files under storage/ only for local/dev when those env vars are unset.
if [ -n "${PASSPORT_PRIVATE_KEY:-}" ] && [ -n "${PASSPORT_PUBLIC_KEY:-}" ]; then
    echo "[entrypoint] using Passport keys from environment"
elif [ "${TRYPOST_TARGET:-}" = "production" ] || [ "${APP_ENV:-}" = "production" ]; then
    echo "[entrypoint] ERROR: PASSPORT_PRIVATE_KEY and PASSPORT_PUBLIC_KEY must be set in production." >&2
    echo "[entrypoint] Generate once with: php artisan passport:keys --show" >&2
    exit 1
elif [ ! -f storage/oauth-private.key ] || [ ! -f storage/oauth-public.key ]; then
    echo "[entrypoint] generating Passport keys (dev fallback)"
    php artisan passport:keys --force
fi

# 10) Personal access client for REST API keys. The seeder is idempotent, so
# fresh self-hosted installs and existing deployments are both safe.
echo "[entrypoint] ensuring Passport personal access client"
php artisan db:seed --class='Database\Seeders\PassportSeeder' --force

# 11) Wayfinder TS regen — Vite needs the files before it boots.
echo "[entrypoint] regenerating wayfinder helpers"
php artisan wayfinder:generate --with-form || true

# 12) Cache strategy: prod = pre-cache; dev = clear.
if [ "${TARGET}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
else
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
    php artisan event:clear
fi

# 13) Permissions. Production php-fpm pool runs as www-data (Alpine default),
# so storage and bootstrap/cache must be writable by that user — Laravel
# needs to write session files, view cache, log files, etc.
if [ "${TARGET}" = "production" ]; then
    chown -R www-data:www-data storage bootstrap/cache
else
    # Dev: ensure UID-mapped user owns runtime dirs.
    APP_UID="${UID:-1000}"
    APP_GID="${GID:-1000}"
    chown -R "${APP_UID}:${APP_GID}" storage bootstrap/cache 2>/dev/null || true
fi

echo "[entrypoint] ready — handing off to supervisord"
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
