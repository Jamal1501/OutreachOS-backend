#!/usr/bin/env sh
set -e

cd /var/www

if [ -n "${DATABASE_URL:-}" ] && [ -z "${DB_URL:-}" ]; then
  export DB_URL="$DATABASE_URL"
fi

export APP_ENV="${APP_ENV:-production}"
export DB_CONNECTION="${DB_CONNECTION:-pgsql}"

php artisan config:clear --no-interaction
exec php artisan schedule:work --no-interaction
