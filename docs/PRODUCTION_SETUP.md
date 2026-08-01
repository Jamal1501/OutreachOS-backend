# Production setup

This document is the production configuration checklist for the web service, queue worker, and cron job.

## Shared Render environment

Keep the application, database, Supabase, provider, billing, and observability values identical across all three Render services. Secrets must be entered directly in Render and must not be pasted into tickets, chat, or logs.

Required launch posture:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://loveframes-outreach-api-1.onrender.com
FRONTEND_URL=https://socialcore.app

DB_CONNECTION=pgsql
DB_HOST=<Supabase pooler host>
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=<Supabase pooler username>
DB_PASSWORD=<secret>
DB_SSLMODE=require
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=3900
QUEUE_TIMEOUT=3600

ACCESS_INVITE_ONLY=false
ACCESS_REQUIRE_VERIFIED_EMAIL=true
FEATURE_RAW_SCRAPER=false
FEATURE_TIKTOK=true
ALLOW_LEGACY_APP_KEY=false

OBSERVABILITY_ALERTS_ENABLED=true
OBSERVABILITY_ERROR_TRACKING_ENABLED=true
OBSERVABILITY_ALERT_EMAIL=<operator email address>
OBSERVABILITY_ALERT_WEBHOOK_URL=
OBSERVABILITY_ERROR_WEBHOOK_URL=
```

Also configure the existing Supabase, Stripe, Apify, OpenAI, CORS, billing, and actor values. `SUPABASE_SERVICE_ROLE_KEY` is required for final account deletion after the recovery window.

Open signup is intentional. Revisit this setting before advertising broadly or offering meaningful free provider credits.

## Resend email delivery

Create a Resend API key and verify the sending domain. Configure the following values on the web service, worker, and cron job:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=
MAIL_HOST=smtp.resend.com
MAIL_PORT=587
MAIL_USERNAME=resend
MAIL_PASSWORD=<Resend API key beginning with re_>
MAIL_FROM_ADDRESS=<verified sender address>
MAIL_FROM_NAME=Social CORE
OBSERVABILITY_ALERT_EMAIL=<operator email address>
```

Port 587 uses STARTTLS automatically. The application uses SMTP so no additional Composer package is required.

After deployment, run the following from a Render shell:

```text
php artisan optimize:clear
php artisan ops:validate-production
php artisan ops:test-alert
```

The last command must produce an email. Then send a real workspace invitation to an email address you control and verify that its link opens the correct workspace.

## Health endpoints

- `/api/health/live` confirms that the web process responds.
- `/api/health/ready` reports component health while retaining HTTP 200 for a degraded non-web component.
- `/api/health/operational` returns HTTP 503 when the queue worker, cron heartbeat, recent failed jobs, or recent failed Stripe webhooks are unhealthy.

Use the operational endpoint for external monitoring when a monitor is added. Do not replace Render's web-process liveness check with it unless brief worker or cron deployment gaps are acceptable.

## Release validation

Every release must pass both backend CI jobs:

- Fast tests and formatting on SQLite
- Full migration and test run on PostgreSQL 16

The frontend workflow must pass installation, lint, tests, and production build.

