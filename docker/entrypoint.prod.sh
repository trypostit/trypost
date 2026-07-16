#!/bin/sh

set -eu

cd /var/www/html

echo "[entrypoint] waiting for postgres"
attempt=0
until pg_isready \
    -h "${DB_HOST:-pgsql}" \
    -p "${DB_PORT:-5432}" \
    -U "${DB_USERNAME}" \
    -d "${DB_DATABASE}" >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -eq 60 ]; then
        echo "[entrypoint] postgres did not become ready" >&2
        exit 1
    fi
    sleep 1
done

mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

if [ ! -L public/storage ]; then
    php artisan storage:link --force
fi

if [ ! -f storage/oauth-private.key ]; then
    php artisan passport:keys --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

chown -R www-data:www-data storage bootstrap/cache

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
