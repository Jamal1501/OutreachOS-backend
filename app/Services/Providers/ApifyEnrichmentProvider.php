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

    public function enrich(string $platform, array $urls, array $hashtags, int $limit): ProviderRunResult
    {
        $module = $this->scrapers->systemDefaultModule($platform, 'enrichment');
        $actorKey = (string) ($module['actorKey'] ?? ($platform === 'instagram' ? 'instagram_profile' : 'tiktok_profile'));

if ($platform === 'instagram') {
    $cleanUrls = array_values(array_unique(array_filter(array_map(
        fn (string $url) => trim($url),
        $urls
    ))));

    $input = [
        'addParentData' => false,
        'directUrls' => $cleanUrls,
        'resultsType' => 'details',
        'resultsLimit' => max(1, count($cleanUrls)),
    ];
} else {
            $profiles = array_values(array_filter(array_map(function (string $url) {
                if (preg_match('~tiktok\.com/@([^/?#]+)~i', $url, $matches)) {
                    return $matches[1];
                }

                return null;
            }, $urls)));

            $input = [
                'profiles' => $profiles,
                'excludePinnedPosts' => false,
                'shouldDownloadAvatars' => false,
                'shouldDownloadCovers' => false,
                'shouldDownloadSlideshowImages' => false,
                'shouldDownloadSubtitles' => false,
                'shouldDownloadVideos' => false,
            ];
        }

        return $this->executor->run($actorKey, $platform, $input);
    }
}
