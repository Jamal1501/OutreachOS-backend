#!/usr/bin/env sh
set -e

cd /var/www

# Render may expose Postgres as DATABASE_URL; Laravel config also accepts DB_URL.
if [ -n "${DATABASE_URL:-}" ] && [ -z "${DB_URL:-}" ]; then
  export DB_URL="$DATABASE_URL"
fi

export APP_ENV="${APP_ENV:-production}"
export DB_CONNECTION="${DB_CONNECTION:-pgsql}"
export CACHE_STORE="${CACHE_STORE:-file}"
export CACHE_DRIVER="${CACHE_DRIVER:-file}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-database}"
export DB_QUEUE_CONNECTION="${DB_QUEUE_CONNECTION:-pgsql}"

php artisan config:clear --no-interaction
exec php artisan queue:work database --timeout="${QUEUE_TIMEOUT:-600}" --tries="${QUEUE_TRIES:-1}" --sleep="${QUEUE_SLEEP:-3}" --no-interaction
