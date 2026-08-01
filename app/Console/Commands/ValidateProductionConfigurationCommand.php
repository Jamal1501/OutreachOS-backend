<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ValidateProductionConfigurationCommand extends Command
{
    protected $signature = 'ops:validate-production {--strict : Treat recommendations as failures}';

    protected $description = 'Validate production settings without printing secrets.';

    public function handle(): int
    {
        $database = (array) config('database.connections.pgsql', []);
        $queueTimeout = (int) config('observability.health.queue_timeout', 3600);
        $queueRetryAfter = (int) config('queue.connections.database.retry_after', 0);
        $mailDriver = (string) config('mail.default');
        $mailConfigured = ! in_array($mailDriver, ['log', 'array'], true)
            && ($mailDriver !== 'resend' || $this->present(config('services.resend.key')));
        $alertsEnabled = (bool) config('observability.alerts.enabled');
        $hasAlertDestination = $this->present(config('observability.alerts.email'))
            || $this->present(config('observability.alerts.webhook_url'));
        $tiktokEnabled = (bool) config('outreach.launch.enable_tiktok');

        $checks = [
            $this->check('Production environment', app()->environment('production'), true, 'APP_ENV must be production.'),
            $this->check('Debug mode disabled', ! config('app.debug'), true, 'APP_DEBUG must be false.'),
            $this->check('HTTPS application URL', str_starts_with((string) config('app.url'), 'https://'), true, 'APP_URL must use HTTPS.'),
            $this->check('HTTPS frontend URL', str_starts_with((string) config('app.frontend_url'), 'https://'), true, 'FRONTEND_URL must use HTTPS.'),
            $this->check('PostgreSQL selected', config('database.default') === 'pgsql', true, 'DB_CONNECTION must be pgsql.'),
            $this->check('Remote database host', $this->present($database['host'] ?? null) && ! in_array($database['host'], ['127.0.0.1', 'localhost'], true), true, 'DB_HOST must point to Supabase.'),
            $this->check('Database name configured', $this->present($database['database'] ?? null) && $database['database'] !== 'laravel', true, 'DB_DATABASE is missing.'),
            $this->check('Database credentials configured', $this->present($database['username'] ?? null) && $this->present($database['password'] ?? null), true, 'DB_USERNAME or DB_PASSWORD is missing.'),
            $this->check('Database SSL required', ($database['sslmode'] ?? null) === 'require', true, 'DB_SSLMODE must be require.'),
            $this->check('Database queue selected', config('queue.default') === 'database', true, 'QUEUE_CONNECTION must be database.'),
            $this->check('Queue retry margin', $queueRetryAfter > $queueTimeout, true, 'DB_QUEUE_RETRY_AFTER must exceed QUEUE_TIMEOUT.'),
            $this->check('Application key configured', $this->present(config('app.key')), true, 'APP_KEY is missing.'),
            $this->check('Supabase authentication configured', $this->present(config('services.supabase.url')) && $this->present(config('services.supabase.anon_key')), true, 'Supabase URL or anonymous key is missing.'),
            $this->check('Supabase deletion access configured', $this->present(config('services.supabase.service_role_key')), true, 'SUPABASE_SERVICE_ROLE_KEY is missing.'),
            $this->check('Stripe configured', $this->present(config('services.stripe.secret_key')) && $this->present(config('services.stripe.publishable_key')) && $this->present(config('services.stripe.webhook_secret')), true, 'Stripe keys or webhook secret are missing.'),
            $this->check('Apify configured', $this->present(config('services.apify.token')) && $this->present(config('services.apify.actors.instagram_discovery')) && $this->present(config('services.apify.actors.instagram_profile')), true, 'Apify token or required Instagram actors are missing.'),
            $this->check('TikTok actors configured', ! $tiktokEnabled || ($this->present(config('services.apify.actors.tiktok_discovery')) && $this->present(config('services.apify.actors.tiktok_profile'))), true, 'TikTok is enabled but its actors are missing.'),
            $this->check('Raw scraper disabled', ! config('outreach.launch.enable_raw_scraper'), true, 'FEATURE_RAW_SCRAPER must remain false.'),
            $this->check('Legacy application key disabled', ! config('services.app_security.allow_legacy_key'), true, 'ALLOW_LEGACY_APP_KEY must be false.'),
            $this->check('Verified email required', config('outreach.launch.require_verified_email'), true, 'ACCESS_REQUIRE_VERIFIED_EMAIL must be true.'),
            $this->check('Operational alerts deliverable', $alertsEnabled && $hasAlertDestination, true, 'Enable alerts and configure an email or webhook destination.'),
            $this->check('Real mail transport', $mailConfigured, false, 'Set MAIL_MAILER=resend and configure RESEND_API_KEY before using invitations or email alerts.'),
            $this->check('Render-safe Resend transport', $mailDriver === 'resend', false, 'Use MAIL_MAILER=resend so Render sends mail over HTTPS instead of SMTP.'),
        ];

        $failures = 0;
        $warnings = 0;
        foreach ($checks as $check) {
            $status = $check['ok'] ? 'OK' : ($check['required'] ? 'FAIL' : 'WARN');
            $this->line(sprintf('[%s] %s%s', $status, $check['label'], $check['ok'] ? '' : ': '.$check['message']));
            if (! $check['ok']) {
                $check['required'] ? $failures++ : $warnings++;
            }
        }

        $this->newLine();
        $this->line(sprintf('Required failures: %d; recommendations: %d.', $failures, $warnings));

        return $failures > 0 || ($this->option('strict') && $warnings > 0)
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function check(string $label, bool $ok, bool $required, string $message): array
    {
        return compact('label', 'ok', 'required', 'message');
    }

    private function present(mixed $value): bool
    {
        return trim((string) $value) !== '';
    }
}
