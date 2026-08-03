#!/usr/bin/env sh
set -e

cd /var/www

. /var/www/runtime-database-env.sh

export CACHE_STORE="${CACHE_STORE:-file}"
export CACHE_DRIVER="${CACHE_DRIVER:-file}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-database}"
export DB_QUEUE_CONNECTION="${DB_QUEUE_CONNECTION:-pgsql}"
export DB_QUEUE_RETRY_AFTER="${DB_QUEUE_RETRY_AFTER:-3900}"

php artisan config:clear --no-interaction
exec php artisan queue:work database --queue="${QUEUE_NAMES:-notifications,default}" --timeout="${QUEUE_TIMEOUT:-3600}" --tries="${QUEUE_TRIES:-1}" --sleep="${QUEUE_SLEEP:-3}" --no-interaction
