<?php

namespace App\Jobs;

use App\Services\PipelineDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public string $jobId,
        public array $payload,
    ) {}

    public function handle(PipelineDiscoveryService $pipeline): void
    {
        $pipeline->runJob($this->jobId, $this->payload);
    }
}