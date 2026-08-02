<?php

namespace Tests\Unit;

use App\DataTransferObjects\ProviderRunResult;
use App\Services\Providers\ApifyEnrichmentProvider;
use App\Services\Providers\ApifyRunExecutor;
use App\Services\ScraperRegistryService;
use App\Services\WorkspaceBillingService;
use Mockery;
use Tests\TestCase;

class ApifyEnrichmentProviderSpendReservationTest extends TestCase
{
    public function test_deep_enrichment_reserves_the_sum_of_provider_batch_caps_before_starting(): void
    {
        $executor = Mockery::mock(ApifyRunExecutor::class);
        $scrapers = Mockery::mock(ScraperRegistryService::class);
        $billing = Mockery::mock(WorkspaceBillingService::class);
        $urls = [
            'https://instagram.com/creator-one',
            'https://instagram.com/creator-two',
            'https://instagram.com/creator-three',
        ];
        $scrapers->shouldReceive('resolvePipelineModule')->once()->andReturn([
            'key' => 'instagram.enrichment.deep',
            'actorKey' => 'instagram_profile',
            'actorId' => 'actor-id',
            'maxBatchSize' => 2,
        ]);
        $scrapers->shouldReceive('providerChargeLimitUsd')->twice()->andReturn(1.25, 0.75);
        $billing->shouldReceive('reserveApify')
            ->once()
            ->withArgs(fn (...$arguments) => $arguments[0] === 'workspace-1'
                && $arguments[1] === 'instagram.enrichment.deep'
                && $arguments[5] === 2.0)
            ->andReturn([
                'usage_event_id' => 'usage-1',
                'credit_bucket' => 'scrape',
                'credit_cost' => 15,
                'units' => 3,
                'remaining_balance' => 85,
            ]);
        $executor->shouldReceive('run')->twice()->andReturn(
            new ProviderRunResult('apify', 'instagram', 'run-1', 'dataset-1', [], billing: ['providerCostUsd' => 0.3]),
            new ProviderRunResult('apify', 'instagram', 'run-2', 'dataset-2', [], billing: ['providerCostUsd' => 0.2]),
        );
        $billing->shouldReceive('consumeReservation')->once();

        $result = (new ApifyEnrichmentProvider($executor, $scrapers, $billing))->enrich(
            'instagram',
            $urls,
            ['skincare'],
            3,
            ['workspaceId' => 'workspace-1', 'planId' => 'enterprise'],
        );

        $this->assertSame('usage-1', $result->billing['usageEventId']);
    }
}
