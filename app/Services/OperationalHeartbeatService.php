<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OperationalHeartbeatService
{
    private int $lastQueueHeartbeatAt = 0;

    public function queueWorkerIdle(string $source = 'queue-loop', bool $force = false): void
    {
        $this->beatQueueWorker([
            'state' => 'idle',
            'source' => $source,
        ], $force);
    }

    public function queueWorkerBusy(string $jobId, string $stage, array $metadata = [], bool $force = false): void
    {
        $this->beatQueueWorker(array_merge($metadata, [
            'state' => 'busy',
            'source' => 'pipeline-job',
            'jobId' => $jobId,
            'stage' => $stage,
        ]), $force);
    }

    private function beatQueueWorker(array $metadata, bool $force): void
    {
        $now = time();
        if (! $force && $now - $this->lastQueueHeartbeatAt < 20) {
            return;
        }
        $this->lastQueueHeartbeatAt = $now;

        try {
            if (! Schema::hasTable('operational_heartbeats')) {
                return;
            }

            $timestamp = now();
            DB::table('operational_heartbeats')->upsert([[
                'name' => 'queue-worker',
                'last_seen_at' => $timestamp,
                'metadata' => json_encode($metadata),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]], ['name'], ['last_seen_at', 'metadata', 'updated_at']);
        } catch (Throwable $exception) {
            // Health reporting must never interrupt billable provider work.
            Log::warning('Queue worker heartbeat update failed', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
