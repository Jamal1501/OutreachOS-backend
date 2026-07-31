#!/usr/bin/env sh
set -e

. /var/www/runtime-database-env.sh

php artisan config:clear --no-interaction
php artisan migrate --force --no-interaction
php artisan serve --host 0.0.0.0 --port ${PORT:-10000}
