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

    public function discover(string $platform, array $hashtags, int $limit): ProviderRunResult
    {
        $module = $this->scrapers->systemDefaultModule($platform, 'discovery');
        $actorKey = (string) ($module['actorKey'] ?? ($platform === 'instagram' ? 'instagram_discovery' : 'tiktok_discovery'));

        return $this->executor->run($actorKey, $platform, [
            'hashtags' => array_values($hashtags),
            'keywordSearch' => false,
            'resultsLimit' => $limit,
            'resultsType' => 'reels',
        ]);
    }
}
