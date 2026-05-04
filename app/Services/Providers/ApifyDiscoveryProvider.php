<?php

namespace App\Services\Providers;

use App\Contracts\DiscoveryProvider;
use App\DataTransferObjects\ProviderRunResult;
use App\Services\ScraperRegistryService;

class ApifyDiscoveryProvider implements DiscoveryProvider
{
    public function __construct(
        private ApifyRunExecutor $executor,
        private ScraperRegistryService $scrapers,
    ) {
    }

    public function discover(string $platform, array $hashtags, int $limit, array $context = []): ProviderRunResult
    {
        $planId = (string) ($context['planId'] ?? 'free');
        $module = $this->scrapers->resolvePipelineModule($planId, $platform, 'discovery', $context['moduleKey'] ?? null);
        $seedCount = max(1, count(array_filter($hashtags, fn ($value) => trim((string) $value) !== '')));
        $effectiveResultsLimit = $this->scrapers->normalizeDiscoveryLimitForSeeds($limit, $seedCount, $module);
        $totalFetchLimit = max($limit, $effectiveResultsLimit * $seedCount);
        $actorKey = (string) ($module['actorKey'] ?? ($platform === 'instagram' ? 'instagram_discovery' : 'tiktok_discovery'));

        $result = $this->executor->run($actorKey, $platform, [
            'hashtags' => array_values($hashtags),
            'keywordSearch' => false,
            'resultsLimit' => $effectiveResultsLimit,
            'resultsType' => 'reels',
        ], array_merge($context, [
            'moduleKey' => $module['key'],
            'stage' => 'discovery',
            'fetchLimit' => $totalFetchLimit,
        ]));

        $trimmedItems = count($result->items) > $limit ? array_slice($result->items, 0, $limit) : $result->items;

        return new ProviderRunResult(
            provider: $result->provider,
            platform: $result->platform,
            runId: $result->runId,
            datasetId: $result->datasetId,
            items: $trimmedItems,
            requestPayload: $result->requestPayload,
            responsePayload: $result->responsePayload,
        );
    }
}
