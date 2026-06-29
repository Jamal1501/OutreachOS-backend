#!/usr/bin/env sh
set -e

# Render commonly exposes managed Postgres as DATABASE_URL, while Laravel's
# database config reads DB_URL. Bridge it at runtime without committing secrets.
if [ -n "${DATABASE_URL:-}" ] && [ -z "${DB_URL:-}" ]; then
  export DB_URL="$DATABASE_URL"
fi

if [ -z "${DB_CONNECTION:-}" ]; then
  case "${DB_URL:-}" in
    postgres://*|postgresql://*)
      export DB_CONNECTION=pgsql
      ;;
    mysql://*)
      export DB_CONNECTION=mysql
      ;;
    *)
      if [ "${APP_ENV:-}" = "production" ]; then
        export DB_CONNECTION=pgsql
      fi
      ;;
  esac
fi

php artisan config:clear --no-interaction
php artisan migrate --force --no-interaction
php artisan serve --host 0.0.0.0 --port ${PORT:-10000}
