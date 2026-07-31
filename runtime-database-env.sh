#!/usr/bin/env sh

# Normalize the database environment consistently for every Render process.
if [ -n "${DATABASE_URL:-}" ] && [ -z "${DB_URL:-}" ]; then
  export DB_URL="$DATABASE_URL"
fi

export APP_ENV="${APP_ENV:-production}"
export DB_CONNECTION="${DB_CONNECTION:-pgsql}"

if [ "$APP_ENV" = "production" ] && [ "$DB_CONNECTION" = "sqlite" ]; then
  export DB_CONNECTION="pgsql"
fi

if [ "$APP_ENV" = "production" ] && [ "$DB_CONNECTION" = "pgsql" ]; then
  export DB_SSLMODE="${DB_SSLMODE:-require}"
fi

if [ -z "${DB_URL:-}" ]; then
  missing_database_variables=""

  [ -z "${DB_HOST:-}" ] && missing_database_variables="$missing_database_variables DB_HOST"
  [ -z "${DB_PORT:-}" ] && missing_database_variables="$missing_database_variables DB_PORT"
  [ -z "${DB_DATABASE:-}" ] && missing_database_variables="$missing_database_variables DB_DATABASE"
  [ -z "${DB_USERNAME:-}" ] && missing_database_variables="$missing_database_variables DB_USERNAME"
  [ -z "${DB_PASSWORD:-}" ] && missing_database_variables="$missing_database_variables DB_PASSWORD"

  if [ -n "$missing_database_variables" ]; then
    echo "ERROR: Database configuration is incomplete for this Render service." >&2
    echo "Missing environment variables:$missing_database_variables" >&2
    echo "Attach the shared production environment group or copy the database variables to this service." >&2
    exit 1
  fi
fi
