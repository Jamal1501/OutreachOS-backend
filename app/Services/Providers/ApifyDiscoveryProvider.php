<?php

namespace App\Services\Providers;

use App\Contracts\DiscoveryProvider;
use App\DataTransferObjects\ProviderRunResult;

class ApifyDiscoveryProvider implements DiscoveryProvider
{
    public function __construct(private ApifyRunExecutor $executor)
    {
    }

    public function discover(string $platform, array $hashtags, int $limit): ProviderRunResult
    {
        $actorKey = $platform === 'instagram' ? 'instagram_discovery' : 'tiktok_discovery';

        return $this->executor->run($actorKey, $platform, [
            'hashtags' => array_values($hashtags),
            'keywordSearch' => false,
            'resultsLimit' => $limit,
            'resultsType' => 'reels',
        ]);
    }
}
