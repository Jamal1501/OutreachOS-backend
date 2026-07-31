#!/usr/bin/env sh
set -e

cd /var/www

# Render can replace Docker CMD for workers and cron jobs. Keeping database
# validation in ENTRYPOINT ensures those overrides cannot bypass it.
. /var/www/runtime-database-env.sh

exec "$@"
