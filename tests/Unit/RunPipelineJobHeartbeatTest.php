<?php

namespace Tests\Unit;

use App\Jobs\RunPipelineJob;
use App\Services\OperationalHeartbeatService;
use App\Services\PipelineDiscoveryService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RunPipelineJobHeartbeatTest extends TestCase
{
    public function test_pipeline_job_returns_the_worker_to_idle_after_success(): void
    {
        $jobId = '019fbf5b-15a7-78d0-b23b-c47be35195fe';
        $pipeline = Mockery::mock(PipelineDiscoveryService::class);
        $heartbeat = Mockery::mock(OperationalHeartbeatService::class);

        $heartbeat->shouldReceive('queueWorkerBusy')
            ->once()
            ->with($jobId, 'claiming', [], true);
        $pipeline->shouldReceive('claimJobExecution')->once()->with($jobId, 'direct')->andReturnTrue();
        $pipeline->shouldReceive('runJob')->once()->with($jobId, ['platform' => 'instagram'])->andReturn([]);
        $heartbeat->shouldReceive('queueWorkerIdle')
            ->once()
            ->with('pipeline-job-finished', true);

        (new RunPipelineJob($jobId, ['platform' => 'instagram']))->handle($pipeline, $heartbeat);
    }

    public function test_pipeline_job_returns_the_worker_to_idle_after_failure(): void
    {
        $jobId = '019fbf5b-bc45-7c35-8690-452ae1f9a267';
        $pipeline = Mockery::mock(PipelineDiscoveryService::class);
        $heartbeat = Mockery::mock(OperationalHeartbeatService::class);

        $heartbeat->shouldReceive('queueWorkerBusy')->once();
        $pipeline->shouldReceive('claimJobExecution')->once()->andReturnTrue();
        $pipeline->shouldReceive('runJob')->once()->andThrow(new RuntimeException('provider failed'));
        $heartbeat->shouldReceive('queueWorkerIdle')
            ->once()
            ->with('pipeline-job-finished', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider failed');

        (new RunPipelineJob($jobId, []))->handle($pipeline, $heartbeat);
    }
}
