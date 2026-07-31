<?php

namespace App\Services;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ObservabilityService
{
    public function reportException(Throwable $exception, array $context = []): void
    {
        if (! $this->errorTrackingEnabled()) {
            return;
        }

        $this->sendAlert('application.exception', 'Unhandled application exception', [
            'exception' => $this->exceptionPayload($exception),
            'context' => $this->redact($context),
        ], 'error', (string) config('observability.error_tracking.webhook_url'));
    }

    public function reportQueueFailure(JobFailed $event): void
    {
        $payload = [
            'connection' => $event->connectionName,
            'queue' => method_exists($event->job, 'getQueue') ? $event->job->getQueue() : null,
            'job' => method_exists($event->job, 'resolveName') ? $event->job->resolveName() : get_class($event->job),
            'attempts' => method_exists($event->job, 'attempts') ? $event->job->attempts() : null,
            'exception' => $this->exceptionPayload($event->exception),
        ];

        $this->sendAlert('queue.job_failed', 'Queue job failed', $payload, 'critical');
    }

    public function reportWebhookFailure(string $provider, string $eventId, string $type, Throwable|string $error, array $context = []): void
    {
        $this->sendAlert('webhook.failed', ucfirst($provider).' webhook failed', [
            'provider' => $provider,
            'event_id' => $eventId,
            'type' => $type,
            'error' => $error instanceof Throwable ? $this->exceptionPayload($error) : ['message' => $error],
            'context' => $this->redact($context),
        ], 'critical');
    }

    public function reportBillingEvent(string $workspaceId, string $eventType, array $metadata = [], ?string $billingAccountId = null, ?string $subjectId = null): void
    {
        $metadata = array_merge([
            'billing_account_id' => $billingAccountId,
            'observed_at' => now()->toIso8601String(),
        ], $metadata);

        $this->audit($workspaceId, 'billing_'.$eventType, 'billing', $subjectId, $metadata);
    }

    public function audit(string $workspaceId, string $eventType, ?string $subjectType = null, ?string $subjectId = null, array $metadata = [], ?string $actorUserId = null): void
    {
        if ($workspaceId === '' || ! Schema::hasTable('workspace_audit_events')) {
            return;
        }

        try {
            DB::table('workspace_audit_events')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'actor_user_id' => $actorUserId,
                'event_type' => $eventType,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'metadata' => json_encode($this->redact($metadata), JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('observability audit write failed', [
                'workspace_id' => $workspaceId,
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function sendAlert(string $eventType, string $message, array $payload = [], string $severity = 'warning', ?string $webhookUrl = null): void
    {
        $alert = [
            'service' => (string) config('observability.service'),
            'environment' => (string) config('observability.environment'),
            'severity' => $severity,
            'event_type' => $eventType,
            'message' => $message,
            'occurred_at' => now()->toIso8601String(),
            'payload' => $this->redact($payload),
        ];

        Log::channel(config('logging.default'))->log($severity === 'critical' ? 'critical' : ($severity === 'error' ? 'error' : 'warning'), $message, $alert);

        if (! $this->alertsEnabled()) {
            return;
        }

        $url = trim((string) ($webhookUrl ?: config('observability.alerts.webhook_url')));
        if ($url === '') {
            return;
        }

        try {
            Http::timeout((int) config('observability.alerts.timeout', 5))
                ->acceptJson()
                ->asJson()
                ->post($url, $alert);
        } catch (Throwable $exception) {
            Log::warning('observability alert delivery failed', [
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function alertsEnabled(): bool
    {
        return (bool) config('observability.alerts.enabled', false);
    }

    private function errorTrackingEnabled(): bool
    {
        return (bool) config('observability.error_tracking.enabled', false);
    }

    private function exceptionPayload(Throwable $exception): array
    {
        return [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }

    private function redact(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = Str::lower((string) $key);
            if (Str::contains($normalizedKey, ['token', 'secret', 'password', 'authorization', 'api_key', 'apikey'])) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return Arr::where($redacted, fn ($value) => $value !== null);
    }
}
