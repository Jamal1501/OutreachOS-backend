<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => (string) config('observability.service', 'social-core-api'),
            'checkedAt' => now()->toIso8601String(),
        ]);
    }

    public function ready(): JsonResponse
    {
        return $this->readinessResponse(false, false);
    }

    public function operational(): JsonResponse
    {
        return $this->readinessResponse(true, false);
    }

    public function operationalDetails(Request $request): JsonResponse
    {
        $configuredToken = trim((string) config('observability.health.details_token'));
        $providedToken = trim((string) ($request->bearerToken() ?: $request->header('X-Operational-Token')));

        if ($configuredToken === '' || $providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        return $this->readinessResponse(true, true);
    }

    private function readinessResponse(bool $failWhenDegraded, bool $includeDetails): JsonResponse
    {
        $checks = [
            'database' => $this->databaseCheck(),
            'cache' => $this->cacheCheck(),
            'queue' => $this->queueCheck(),
            'processes' => $this->processHeartbeatCheck(),
            'stripeWebhooks' => $this->stripeWebhookCheck(),
        ];

        $failed = collect($checks)->contains(fn (array $check) => $check['status'] === 'fail');
        $degraded = collect($checks)->contains(fn (array $check) => $check['status'] === 'degraded');
        $status = $failed ? 'fail' : ($degraded ? 'degraded' : 'ok');

        $payload = [
            'status' => $status,
            'service' => (string) config('observability.service', 'social-core-api'),
            'checkedAt' => now()->toIso8601String(),
        ];
        if ($includeDetails) {
            $payload['checks'] = $checks;
        }

        return response()->json($payload, $failed || ($failWhenDegraded && $degraded) ? 503 : 200);
    }

    private function databaseCheck(): array
    {
        try {
            DB::select('select 1');

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'fail', 'message' => 'Database check failed'];
        }
    }

    private function cacheCheck(): array
    {
        try {
            $key = 'health:ready:'.sha1((string) now()->timestamp);
            Cache::put($key, 'ok', 30);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);

            return ['status' => $ok ? 'ok' : 'fail'];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'fail', 'message' => 'Cache check failed'];
        }
    }

    private function queueCheck(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['status' => 'degraded', 'message' => 'failed_jobs table missing'];
        }

        $windowMinutes = (int) config('observability.health.failed_jobs_window_minutes', 15);
        $threshold = (int) config('observability.health.failed_jobs_threshold', 0);
        $failedJobs = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subMinutes($windowMinutes))
            ->count();

        $pendingJobs = null;
        if (Schema::hasTable('jobs')) {
            $pendingJobs = DB::table('jobs')->count();
        }

        $maxPending = (int) config('observability.health.max_pending_jobs', 500);
        $status = $failedJobs > $threshold || ($pendingJobs !== null && $pendingJobs > $maxPending)
            ? 'degraded'
            : 'ok';

        return [
            'status' => $status,
            'failedJobs' => $failedJobs,
            'failedWindowMinutes' => $windowMinutes,
            'pendingJobs' => $pendingJobs,
        ];
    }

    private function stripeWebhookCheck(): array
    {
        if (! Schema::hasTable('stripe_webhook_events')) {
            return ['status' => 'degraded', 'message' => 'stripe_webhook_events table missing'];
        }

        $windowMinutes = (int) config('observability.health.failed_webhooks_window_minutes', 60);
        $threshold = (int) config('observability.health.failed_webhooks_threshold', 0);
        $failedWebhooks = DB::table('stripe_webhook_events')
            ->where('status', 'failed')
            ->where('updated_at', '>=', now()->subMinutes($windowMinutes))
            ->count();

        return [
            'status' => $failedWebhooks > $threshold ? 'degraded' : 'ok',
            'failedWebhooks' => $failedWebhooks,
            'failedWindowMinutes' => $windowMinutes,
        ];
    }

    private function processHeartbeatCheck(): array
    {
        if (! Schema::hasTable('operational_heartbeats')) {
            return ['status' => 'degraded', 'message' => 'Process heartbeat storage is not initialized'];
        }

        $heartbeats = DB::table('operational_heartbeats')
            ->whereIn('name', ['scheduler', 'queue-worker'])
            ->get(['name', 'last_seen_at', 'metadata'])
            ->keyBy('name');
        $stale = collect(['scheduler', 'queue-worker'])->filter(function (string $name) use ($heartbeats) {
            $lastSeen = $heartbeats->get($name)?->last_seen_at;

            return ! $lastSeen || CarbonImmutable::parse($lastSeen)->lt(now()->subMinutes(3));
        })->values()->all();
        $processes = collect(['scheduler', 'queue-worker'])->mapWithKeys(function (string $name) use ($heartbeats, $stale) {
            $heartbeat = $heartbeats->get($name);
            $metadata = $heartbeat && is_string($heartbeat->metadata)
                ? (json_decode($heartbeat->metadata, true) ?: [])
                : [];
            $state = in_array($name, $stale, true)
                ? 'stale'
                : (($metadata['state'] ?? null) === 'busy' ? 'busy' : 'ok');

            return [$name => array_merge([
                'status' => $state,
                'lastSeenAt' => $heartbeat?->last_seen_at,
            ], array_intersect_key($metadata, array_flip([
                'jobId',
                'stage',
                'providerPhase',
                'providerRunId',
                'providerStatus',
            ])))];
        })->all();

        return [
            'status' => $stale === [] ? 'ok' : 'degraded',
            'staleProcesses' => $stale,
            'processes' => $processes,
        ];
    }
}
