#!/usr/bin/env sh
set -e

cd /var/www

. /var/www/runtime-database-env.sh

php artisan config:clear --no-interaction
exec php artisan schedule:run --no-interaction
