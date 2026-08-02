<?php

namespace Tests\Feature;

use App\Services\Providers\ApifyRunExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ApifyRunExecutorHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    public function test_apify_polling_keeps_the_worker_heartbeat_active_until_success(): void
    {
        config([
            'services.apify.token' => 'test-token',
            'services.apify.actors.instagram_discovery' => 'test-actor',
        ]);
        Sleep::fake();

        $pollStatuses = ['RUNNING', 'RUNNING', 'SUCCEEDED'];
        Http::fake(function (Request $request) use (&$pollStatuses) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/acts/test-actor/runs')) {
                return Http::response(['data' => ['id' => 'run-1']], 201);
            }

            if (str_contains($request->url(), '/actor-runs/run-1')) {
                $status = array_shift($pollStatuses) ?: 'SUCCEEDED';

                return Http::response(['data' => [
                    'id' => 'run-1',
                    'status' => $status,
                    'defaultDatasetId' => 'dataset-1',
                    'usageTotalUsd' => 0.01,
                ]]);
            }

            if (str_contains($request->url(), '/datasets/dataset-1/items')) {
                return Http::response([['id' => 'creator-1']]);
            }

            return Http::response(['error' => 'Unexpected test request'], 500);
        });

        $heartbeats = [];
        $result = app(ApifyRunExecutor::class)->run(
            'instagram_discovery',
            'instagram',
            ['hashtags' => ['pilot'], 'resultsLimit' => 1],
            ['heartbeat' => function (array $metadata) use (&$heartbeats): void {
                $heartbeats[] = $metadata;
            }],
        );

        $statuses = collect($heartbeats)->pluck('providerStatus')->filter()->values()->all();

        $this->assertSame('run-1', $result->runId);
        $this->assertCount(1, $result->items);
        $this->assertSame(['RUNNING', 'RUNNING', 'SUCCEEDED'], $statuses);
        $this->assertGreaterThanOrEqual(7, count($heartbeats));
        Sleep::assertSleptTimes(2);
        Http::assertSentCount(5);
    }
}
