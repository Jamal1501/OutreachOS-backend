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

  case "${DB_HOST:-}" in
    127.0.0.1|localhost|::1)
      missing_database_variables="$missing_database_variables DB_HOST(non-local value required)"
      ;;
  esac

  if [ "${DB_DATABASE:-}" = "laravel" ]; then
    missing_database_variables="$missing_database_variables DB_DATABASE(non-default value required)"
  fi

  if [ -n "$missing_database_variables" ]; then
    echo "ERROR: Database configuration is incomplete for this Render service." >&2
    echo "Missing environment variables:$missing_database_variables" >&2
    echo "Attach the shared production environment group or copy the database variables to this service." >&2
    exit 1
  fi
fi

# Explicitly export values that may have been normalized above so every child
# process receives the same configuration, including custom Render commands.
export DB_URL DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SSLMODE

if [ -n "${DB_URL:-}" ]; then
  echo "Database configuration validated: connection URL is configured."
else
  echo "Database configuration validated: host, database, and credentials are configured."
fi
