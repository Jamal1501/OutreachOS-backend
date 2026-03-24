<?php

namespace App\Services\Providers;

use App\Contracts\EnrichmentProvider;
use App\DataTransferObjects\ProviderRunResult;

class ApifyEnrichmentProvider implements EnrichmentProvider
{
    public function __construct(private ApifyRunExecutor $executor)
    {
    }

    public function enrich(string $platform, array $urls, array $hashtags, int $limit): ProviderRunResult
    {
        $actorKey = $platform === 'instagram' ? 'instagram_profile' : 'tiktok_profile';

        if ($platform === 'instagram') {
            $input = [
                'addParentData' => false,
                'directUrls' => array_values($urls),
                'onlyPostsNewerThan' => '100 days',
                'resultsLimit' => max(1, min($limit, count($urls))),
                'resultsType' => 'details',
                'search' => $hashtags[0] ?? '',
                'searchLimit' => max(1, min($limit, count($urls))),
                'searchType' => 'hashtag',
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
