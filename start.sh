#!/usr/bin/env sh
set -e

php artisan migrate --force
php artisan serve --host 0.0.0.0 --port ${PORT:-10000}
