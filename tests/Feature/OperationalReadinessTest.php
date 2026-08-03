<?php

namespace Tests\Feature;

use App\Http\Controllers\OperationsController;
use App\Mail\OperationalAlertMail;
use App\Services\ObservabilityService;
use App\Services\OperationalHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OperationalReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const DETAILS_TOKEN = 'test-operational-details-token';

    public function test_operational_health_fails_when_required_processes_are_stale(): void
    {
        $this->getJson('/api/health/operational')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonMissingPath('checks');
    }

    public function test_operational_health_succeeds_when_required_processes_are_current(): void
    {
        foreach (['scheduler', 'queue-worker'] as $name) {
            DB::table('operational_heartbeats')->insert([
                'name' => $name,
                'last_seen_at' => now(),
                'metadata' => json_encode(['test' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson('/api/health/operational')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonMissingPath('checks');
    }

    public function test_persisted_old_heartbeats_are_reported_as_stale(): void
    {
        foreach (['scheduler', 'queue-worker'] as $name) {
            DB::table('operational_heartbeats')->insert([
                'name' => $name,
                'last_seen_at' => now()->subMinutes(4),
                'metadata' => json_encode(['state' => 'idle', 'test' => true]),
                'created_at' => now()->subMinutes(4),
                'updated_at' => now()->subMinutes(4),
            ]);
        }
        config(['observability.health.details_token' => self::DETAILS_TOKEN]);

        $this->getJson('/api/health/operational')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'degraded');

        $this->withToken(self::DETAILS_TOKEN)
            ->getJson('/api/health/operational/details')
            ->assertServiceUnavailable()
            ->assertJsonPath('checks.processes.status', 'degraded')
            ->assertJsonPath('checks.processes.staleProcesses', ['scheduler', 'queue-worker'])
            ->assertJsonPath('checks.processes.processes.scheduler.status', 'stale')
            ->assertJsonPath('checks.processes.processes.queue-worker.status', 'stale');

        $operatorOverview = app(OperationsController::class)->overview()->getData(true);
        $this->assertSame('stale', $operatorOverview['data']['processes']['scheduler']['status']);
        $this->assertSame('stale', $operatorOverview['data']['processes']['queue-worker']['status']);
    }

    public function test_alerts_can_be_delivered_by_email_without_a_webhook(): void
    {
        Mail::fake();
        config([
            'observability.alerts.enabled' => true,
            'observability.alerts.webhook_url' => null,
            'observability.alerts.email' => 'operator@example.test',
        ]);

        app(ObservabilityService::class)->sendAlert(
            'operations.test',
            'Operational alert test',
            ['safe' => true],
        );

        Mail::assertSent(OperationalAlertMail::class, fn (OperationalAlertMail $mail) => $mail->hasTo('operator@example.test'));
    }

    public function test_operational_health_reports_a_working_pipeline_worker_as_busy(): void
    {
        DB::table('operational_heartbeats')->insert([
            'name' => 'scheduler',
            'last_seen_at' => now(),
            'metadata' => json_encode(['source' => 'test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(OperationalHeartbeatService::class)->queueWorkerBusy(
            '019fbf42-18cf-70f2-894c-5f85081cbd47',
            'enrichment_scrape',
            ['providerStatus' => 'RUNNING'],
            force: true,
        );

        config(['observability.health.details_token' => self::DETAILS_TOKEN]);

        $this->withToken(self::DETAILS_TOKEN)
            ->getJson('/api/health/operational/details')
            ->assertOk()
            ->assertJsonPath('checks.processes.status', 'ok')
            ->assertJsonPath('checks.processes.processes.queue-worker.status', 'busy')
            ->assertJsonPath('checks.processes.processes.queue-worker.stage', 'enrichment_scrape')
            ->assertJsonPath('checks.processes.processes.queue-worker.providerStatus', 'RUNNING');
    }

    public function test_detailed_operational_health_requires_the_operator_token(): void
    {
        config(['observability.health.details_token' => self::DETAILS_TOKEN]);

        $this->getJson('/api/health/operational/details')
            ->assertUnauthorized()
            ->assertJsonMissingPath('checks');
    }
}
