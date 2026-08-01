<?php

namespace App\Jobs;

use App\Services\OperationalHeartbeatService;
use App\Services\PipelineDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $jobId,
        public array $payload,
    ) {}

    public function handle(PipelineDiscoveryService $pipeline, OperationalHeartbeatService $heartbeat): void
    {
        $heartbeat->queueWorkerBusy($this->jobId, 'claiming', force: true);

        try {
            if (! $pipeline->claimJobExecution($this->jobId, (string) ($this->job?->getJobId() ?: 'direct'))) {
                return;
            }

            $pipeline->runJob($this->jobId, $this->payload);
        } finally {
            $heartbeat->queueWorkerIdle('pipeline-job-finished', force: true);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(PipelineDiscoveryService::class)->markJobFailed(
            $this->jobId,
            $exception?->getMessage() ?: 'The discovery worker stopped before the run completed.',
        );
    }
}
