<?php

namespace App\Services\Providers;

use App\Contracts\EnrichmentProvider;
use App\DataTransferObjects\ProviderRunResult;
use App\Services\ScraperRegistryService;

class ApifyEnrichmentProvider implements EnrichmentProvider
{
    public function __construct(
        private ApifyRunExecutor $executor,
        private ScraperRegistryService $scrapers,
    ) {
    }

    public function enrich(string $platform, array $urls, array $hashtags, int $limit, array $context = []): ProviderRunResult
    {
        $planId = (string) ($context['planId'] ?? 'free');
        $module = $this->scrapers->resolvePipelineModule($planId, $platform, 'enrichment', $context['moduleKey'] ?? null);
        $actorKey = (string) ($module['actorKey'] ?? ($platform === 'instagram' ? 'instagram_profile' : 'tiktok_profile'));
        $cleanUrls = array_values(array_unique(array_filter(array_map('trim', $urls))));
        $effectiveLimit = $this->scrapers->clampBatchSize(min($limit, count($cleanUrls) ?: $limit), $module);
        $cleanUrls = array_slice($cleanUrls, 0, $effectiveLimit);

        $input = $platform === 'instagram'
            ? [
                'addParentData' => false,
                'directUrls' => $cleanUrls,
                'onlyPostsNewerThan' => '100 days',
                'resultsLimit' => max(1, count($cleanUrls)),
                'resultsType' => 'details',
                'search' => '',
                'searchLimit' => 10,
                'searchType' => 'hashtag',
            ]
            : [
                'directUrls' => $cleanUrls,
                'profiles' => array_values(array_filter(array_map(function (string $url) {
                    $parts = explode('@', $url, 2);
                    $handle = $parts[1] ?? '';
                    return trim(explode('/', $handle)[0] ?? '');
                }, $cleanUrls))),
                'resultsLimit' => max(1, count($cleanUrls)),
                'excludePinnedPosts' => false,
                'shouldDownloadAvatars' => false,
                'shouldDownloadCovers' => false,
                'shouldDownloadSlideshowImages' => false,
                'shouldDownloadSubtitles' => false,
                'shouldDownloadVideos' => false,
            ];

        $result = $this->executor->run($actorKey, $platform, $input, array_merge($context, [
            'moduleKey' => $module['key'],
            'stage' => 'enrichment',
        ]));

        return new ProviderRunResult(
            provider: $result->provider,
            platform: $result->platform,
            runId: $result->runId,
            datasetId: $result->datasetId,
            items: count($result->items) > $limit ? array_slice($result->items, 0, $limit) : $result->items,
            requestPayload: $result->requestPayload,
            responsePayload: $result->responsePayload,
        );
    }
}
