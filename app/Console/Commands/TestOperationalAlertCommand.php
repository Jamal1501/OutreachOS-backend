<?php

namespace App\Console\Commands;

use App\Services\ObservabilityService;
use Illuminate\Console\Command;

class TestOperationalAlertCommand extends Command
{
    protected $signature = 'ops:test-alert';

    protected $description = 'Send a harmless operational test alert using the configured delivery channels.';

    public function handle(ObservabilityService $observability): int
    {
        if (! config('observability.alerts.enabled')) {
            $this->error('Operational alerts are disabled. Set OBSERVABILITY_ALERTS_ENABLED=true.');

            return self::FAILURE;
        }

        $hasWebhook = trim((string) config('observability.alerts.webhook_url')) !== '';
        $hasEmail = trim((string) config('observability.alerts.email')) !== '';
        if (! $hasWebhook && ! $hasEmail) {
            $this->error('No alert destination is configured. Set OBSERVABILITY_ALERT_EMAIL or OBSERVABILITY_ALERT_WEBHOOK_URL.');

            return self::FAILURE;
        }

        $observability->sendAlert('operations.test', 'Operational alert test', [
            'source' => 'ops:test-alert',
            'note' => 'No customer action is required.',
        ]);

        $channels = implode(' and ', array_filter([
            $hasEmail ? 'email' : null,
            $hasWebhook ? 'webhook' : null,
        ]));
        $this->info("Test alert attempted through {$channels}. Confirm that it arrived.");

        return self::SUCCESS;
    }
}
