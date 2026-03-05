#!/usr/bin/env sh
set -e

# Ensure APP_KEY exists (Render env var should provide it)
php artisan key:generate --force || true

# Serve on Render port
php artisan serve --host 0.0.0.0 --port ${PORT:-10000}
