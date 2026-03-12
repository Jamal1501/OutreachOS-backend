<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PipelineDiscoveryService
{
    private const TERMINAL_RUN_STATUSES = ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT', 'TIMED_OUT'];
    private const DEFAULT_POLL_SECONDS = 5;
    private const DEFAULT_TIMEOUT_SECONDS = 300;

    public function __construct(
        private ApifyRowMapper $rowMapper,
        private GoogleSheetsService $sheets,
    ) {
    }

    public function createJob(array $payload): array
    {
        $jobId = (string) Str::uuid();
        $state = [
            'jobId' => $jobId,
            'status' => 'running',
            'currentStep' => 'discovery_scrape',
            'completedSteps' => [],
            'steps' => [],
            'creators' => [],
            'totalCreators' => 0,
            'failedStep' => null,
            'error' => null,
            'request' => $payload,
            'createdAt' => now()->toDateTimeString(),
            'updatedAt' => now()->toDateTimeString(),
        ];

        $this->writeJobState($jobId, $state);

        return $state;
    }

    public function getJobState(string $jobId): ?array
    {
        $path = $this->jobPath($jobId);
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function runJob(string $jobId, array $payload): array
    {
        $sheetId = $payload['sheetId'];
        $platform = $payload['platform'];
        $hashtags = array_values(array_unique(array_filter(array_map(fn ($v) => $this->normalizeHashtag((string) $v), $payload['hashtags'] ?? []))));
        $discoveryLimit = (int) ($payload['discoveryLimit'] ?? 50);
        $enrichmentLimit = (int) ($payload['enrichmentLimit'] ?? 20);
        $dedupeAgainstCRM = (bool) ($payload['dedupeAgainstCRM'] ?? true);

        $stepResults = [];

        try {
            $this->updateJob($jobId, [
                'status' => 'running',
                'currentStep' => 'discovery_scrape',
            ]);

            $discovery = $this->startActorRun(
                $this->discoveryActorKey($platform),
                $this->discoveryInput($platform, $hashtags, $discoveryLimit)
            );
            $discoveryRun = $this->pollRun($discovery['runId']);
            $discoveryItems = $this->fetchDatasetItems($discoveryRun['defaultDatasetId']);
            $stepResults[] = [
                'step' => 'discovery_scrape',
                'status' => 'completed',
                'runId' => $discovery['runId'],
                'itemCount' => count($discoveryItems),
            ];
            $this->completeStep($jobId, 'discovery_scrape', end($stepResults));

            $this->updateJob($jobId, ['currentStep' => 'import_posts']);
            $rawSheet = $this->rawSheetForPlatform($platform);
            $rawRows = $this->rowMapper->mapRowsForSheet($rawSheet, $discoveryItems, ['platform' => $platform]);
            $importedRows = $this->appendRowsIfAny($sheetId, $rawSheet, $rawRows);
            $stepResults[] = [
                'step' => 'import_posts',
                'status' => 'completed',
                'importedRows' => $importedRows,
            ];
            $this->completeStep($jobId, 'import_posts', end($stepResults));

            $this->updateJob($jobId, ['currentStep' => 'extract_urls']);
            [$profiles, $sourceHashtagsByUrl] = $this->extractProfilesFromDiscoveryItems($platform, $discoveryItems, $hashtags);
            if ($dedupeAgainstCRM) {
                $profiles = $this->dedupeProfilesAgainstCrm($sheetId, $platform, $profiles);
            }
            if ($enrichmentLimit > 0) {
                $profiles = array_slice($profiles, 0, $enrichmentLimit);
            }
            $stepResults[] = [
                'step' => 'extract_urls',
                'status' => 'completed',
                'uniqueProfiles' => count($profiles),
            ];
            $this->completeStep($jobId, 'extract_urls', end($stepResults));

            if (count($profiles) === 0) {
                $final = [
                    'message' => 'Pipeline complete',
                    'status' => 'completed',
                    'steps' => $stepResults,
                    'creators' => [],
                    'totalCreators' => 0,
                    'failedStep' => null,
                ];
                $this->updateJob($jobId, [
                    'status' => 'completed',
                    'currentStep' => null,
                    'steps' => $stepResults,
                    'creators' => [],
                    'totalCreators' => 0,
                    'failedStep' => null,
                    'result' => $final,
                ]);
                return $final;
            }

            $this->updateJob($jobId, ['currentStep' => 'enrichment_scrape']);
            $enrichment = $this->startActorRun(
                $this->enrichmentActorKey($platform),
                $this->enrichmentInput($platform, array_column($profiles, 'profileUrl'), $hashtags, $enrichmentLimit)
            );
            $enrichmentRun = $this->pollRun($enrichment['runId']);
            $enrichmentItems = $this->fetchDatasetItems($enrichmentRun['defaultDatasetId']);
            $stepResults[] = [
                'step' => 'enrichment_scrape',
                'status' => 'completed',
                'runId' => $enrichment['runId'],
                'itemCount' => count($enrichmentItems),
            ];
            $this->completeStep($jobId, 'enrichment_scrape', end($stepResults));

            $this->updateJob($jobId, ['currentStep' => 'import_profiles']);
            $enrichedSheet = $this->enrichedSheetForPlatform($platform);
            $enrichedRows = $this->rowMapper->mapRowsForSheet($enrichedSheet, $enrichmentItems, ['platform' => $platform]);
            $importedProfiles = $this->appendRowsIfAny($sheetId, $enrichedSheet, $enrichedRows);
            $creators = $this->buildCreatorsResponse($platform, $enrichmentItems, $sourceHashtagsByUrl, $hashtags);
            $stepResults[] = [
                'step' => 'import_profiles',
                'status' => 'completed',
                'importedRows' => $importedProfiles,
            ];
            $this->completeStep($jobId, 'import_profiles', end($stepResults));

            $final = [
                'message' => 'Pipeline complete',
                'status' => 'completed',
                'steps' => $stepResults,
                'creators' => $creators,
                'totalCreators' => count($creators),
                'failedStep' => null,
            ];

            $this->updateJob($jobId, [
                'status' => 'completed',
                'currentStep' => null,
                'steps' => $stepResults,
                'creators' => $creators,
                'totalCreators' => count($creators),
                'failedStep' => null,
                'result' => $final,
            ]);

            return $final;
        } catch (\Throwable $e) {
            Log::error('Pipeline discovery job failed', [
                'jobId' => $jobId,
                'message' => $e->getMessage(),
            ]);

            $failedStep = $this->getJobState($jobId)['currentStep'] ?? null;
            $this->updateJob($jobId, [
                'status' => 'failed',
                'failedStep' => $failedStep,
                'error' => $e->getMessage(),
                'steps' => $stepResults,
                'currentStep' => $failedStep,
                'result' => [
                    'message' => 'Pipeline failed',
                    'status' => 'failed',
                    'steps' => $stepResults,
                    'creators' => [],
                    'totalCreators' => 0,
                    'failedStep' => $failedStep,
                ],
            ]);

            throw $e;
        }
    }

    private function completeStep(string $jobId, string $step, array $payload): void
    {
        $state = $this->getJobState($jobId) ?? [];
        $completed = $state['completedSteps'] ?? [];
        $completed[] = $step;
        $completed = array_values(array_unique($completed));

        $steps = $state['steps'] ?? [];
        $steps[] = $payload;

        $this->updateJob($jobId, [
            'completedSteps' => $completed,
            'steps' => $steps,
        ]);
    }

    private function updateJob(string $jobId, array $changes): void
    {
        $state = $this->getJobState($jobId) ?? ['jobId' => $jobId];
        $state = array_merge($state, $changes, [
            'updatedAt' => now()->toDateTimeString(),
        ]);
        $this->writeJobState($jobId, $state);
    }

    private function writeJobState(string $jobId, array $state): void
    {
        $dir = dirname($this->jobPath($jobId));
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($this->jobPath($jobId), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function jobPath(string $jobId): string
    {
        return storage_path('app/private/pipeline_jobs/' . $jobId . '.json');
    }

    private function startActorRun(string $actorKey, array $input): array
    {
        $token = (string) config('services.apify.token');
        $actorId = (string) config('services.apify.actors.' . $actorKey);

        if ($token === '' || $actorId === '') {
            throw new RuntimeException('Missing Apify config for actor: ' . $actorKey);
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(90)
            ->post("https://api.apify.com/v2/acts/{$actorId}/runs", $input);

        if (!$response->successful()) {
            throw new RuntimeException('Failed to start Apify actor: ' . $response->body());
        }

        $data = $response->json('data') ?? [];
        $runId = (string) ($data['id'] ?? '');
        if ($runId === '') {
            throw new RuntimeException('Apify run ID missing in response');
        }

        return [
            'runId' => $runId,
            'datasetId' => (string) ($data['defaultDatasetId'] ?? ''),
        ];
    }

    private function pollRun(string $runId): array
    {
        $token = (string) config('services.apify.token');
        if ($token === '') {
            throw new RuntimeException('Missing APIFY_API_TOKEN');
        }

        $deadline = time() + self::DEFAULT_TIMEOUT_SECONDS;
        do {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get("https://api.apify.com/v2/actor-runs/{$runId}");

            if (!$response->successful()) {
                throw new RuntimeException('Failed to poll Apify run: ' . $response->body());
            }

            $run = $response->json('data') ?? [];
            $status = strtoupper((string) ($run['status'] ?? ''));
            if (in_array($status, self::TERMINAL_RUN_STATUSES, true)) {
                if ($status !== 'SUCCEEDED') {
                    throw new RuntimeException('Apify run ended with status ' . $status);
                }
                return $run;
            }

            sleep(self::DEFAULT_POLL_SECONDS);
        } while (time() < $deadline);

        throw new RuntimeException('Timed out while waiting for Apify run ' . $runId);
    }

    private function fetchDatasetItems(string $datasetId): array
    {
        $token = (string) config('services.apify.token');
        if ($token === '') {
            throw new RuntimeException('Missing APIFY_API_TOKEN');
        }
        if ($datasetId === '') {
            return [];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(90)
            ->get("https://api.apify.com/v2/datasets/{$datasetId}/items", [
                'clean' => 'true',
                'format' => 'json',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Failed to fetch dataset items: ' . $response->body());
        }

        $items = json_decode($response->body(), true);
        return is_array($items) ? $items : [];
    }

    private function appendRowsIfAny(string $sheetId, string $sheetName, array $rows): int
    {
        if (count($rows) === 0) {
            return 0;
        }

        $this->sheets->appendRows($sheetId, $sheetName, $rows);
        return count($rows);
    }

    private function extractProfilesFromDiscoveryItems(string $platform, array $items, array $inputHashtags): array
    {
        $profiles = [];
        $sourceHashtagsByUrl = [];

        foreach ($items as $item) {
            $username = trim((string) ($platform === 'instagram'
                ? Arr::get($item, 'ownerUsername', Arr::get($item, 'owner.username', Arr::get($item, 'username', '')))
                : Arr::get($item, 'authorMeta.name', Arr::get($item, 'author.username', Arr::get($item, 'username', '')))));
            $profileUrl = $this->profileUrlFor($platform, $username, $item);
            if ($profileUrl === '') {
                continue;
            }

            $handle = $this->normalizeHandle($username);
            $matchedTags = $this->matchedHashtagsForItem($item, $inputHashtags);

            if (!isset($profiles[$profileUrl])) {
                $profiles[$profileUrl] = [
                    'platform' => $platform,
                    'handle' => $handle,
                    'username' => $username,
                    'profileUrl' => $profileUrl,
                ];
            }

            $sourceHashtagsByUrl[$profileUrl] = array_values(array_unique(array_merge(
                $sourceHashtagsByUrl[$profileUrl] ?? [],
                $matchedTags
            )));
        }

        return [array_values($profiles), $sourceHashtagsByUrl];
    }

    private function dedupeProfilesAgainstCrm(string $sheetId, string $platform, array $profiles): array
    {
        $crmRows = $this->sheets->getRows($sheetId, 'Creators_CRM');
        $existing = [];

        foreach ($crmRows as $row) {
            $crmPlatform = strtolower(trim((string) ($row['Platform'] ?? '')));
            if ($crmPlatform !== strtolower($platform)) {
                continue;
            }

            $handle = strtolower(trim(ltrim((string) ($row['Handle'] ?? ''), '@')));
            $dm = strtolower(trim((string) ($row['DM_Link'] ?? '')));
            if ($handle !== '') {
                $existing['handle:' . $handle] = true;
            }
            if ($dm !== '') {
                $existing['url:' . rtrim($dm, '/')] = true;
            }
        }

        return array_values(array_filter($profiles, function (array $profile) use ($existing) {
            $handleKey = 'handle:' . strtolower(trim(ltrim((string) ($profile['handle'] ?? ''), '@')));
            $urlKey = 'url:' . rtrim(strtolower(trim((string) ($profile['profileUrl'] ?? ''))), '/');
            return !isset($existing[$handleKey]) && !isset($existing[$urlKey]);
        }));
    }

    private function buildCreatorsResponse(string $platform, array $enrichmentItems, array $sourceHashtagsByUrl, array $inputHashtags): array
    {
        $creators = [];

        foreach ($enrichmentItems as $item) {
            $creator = $platform === 'instagram'
                ? $this->normalizeInstagramCreator($item, $sourceHashtagsByUrl, $inputHashtags)
                : $this->normalizeTikTokCreator($item, $sourceHashtagsByUrl, $inputHashtags);

            if ($creator === null) {
                continue;
            }

            $creators[$creator['profileUrl']] = $creator;
        }

        return array_values($creators);
    }

    private function normalizeInstagramCreator(array $item, array $sourceHashtagsByUrl, array $inputHashtags): ?array
    {
        $username = trim((string) Arr::get($item, 'username', Arr::get($item, 'ownerUsername', '')));
        $profileUrl = $this->profileUrlFor('instagram', $username, $item);
        if ($profileUrl === '') {
            return null;
        }

        $latestPosts = Arr::get($item, 'latestPosts', Arr::get($item, 'latest_posts', []));
        $latestPosts = is_array($latestPosts) ? $latestPosts : [];
        $sourceTags = $sourceHashtagsByUrl[$profileUrl] ?? $sourceHashtagsByUrl[rtrim($profileUrl, '/')] ?? [];
        if ($sourceTags === []) {
            $sourceTags = $this->matchedHashtagsForItem($item, $inputHashtags);
        }

        return [
            'id' => (string) (Arr::get($item, 'id', $username ?: md5($profileUrl))),
            'platform' => 'instagram',
            'handle' => $this->normalizeHandle($username),
            'fullName' => $this->nullableString(Arr::get($item, 'fullName', Arr::get($item, 'ownerFullName'))),
            'profileUrl' => $profileUrl,
            'followers' => $this->nullableInt(Arr::get($item, 'followersCount', Arr::get($item, 'followers'))),
            'engagementRate' => $this->nullableFloat($this->estimateInstagramEngagementRate($item)),
            'email' => $this->nullableString(Arr::get($item, 'email_from_bio', $this->extractEmailFromText((string) Arr::get($item, 'biography', Arr::get($item, 'bio', ''))))),
            'bio' => $this->nullableString(Arr::get($item, 'biography', Arr::get($item, 'bio'))),
            'postsCount' => $this->nullableInt(Arr::get($item, 'postsCount', Arr::get($item, 'posts_count'))),
            'avgLikes' => $this->nullableFloat($this->averageFromLatestPosts($latestPosts, 'likesCount')),
            'avgComments' => $this->nullableFloat($this->averageFromLatestPosts($latestPosts, 'commentsCount')),
            'isVerified' => $this->nullableBool(Arr::get($item, 'verified', Arr::get($item, 'is_verified'))),
            'readyToMerge' => true,
            'sourceHashtags' => $sourceTags,
        ];
    }

    private function normalizeTikTokCreator(array $item, array $sourceHashtagsByUrl, array $inputHashtags): ?array
    {
        $username = trim((string) Arr::get($item, 'username', Arr::get($item, 'authorMeta.name', Arr::get($item, 'author.username', ''))));
        $profileUrl = $this->profileUrlFor('tiktok', $username, $item);
        if ($profileUrl === '') {
            return null;
        }

        $latestPosts = Arr::get($item, 'latestPosts', Arr::get($item, 'latest_posts', []));
        $latestPosts = is_array($latestPosts) ? $latestPosts : [];
        $sourceTags = $sourceHashtagsByUrl[$profileUrl] ?? $sourceHashtagsByUrl[rtrim($profileUrl, '/')] ?? [];
        if ($sourceTags === []) {
            $sourceTags = $this->matchedHashtagsForItem($item, $inputHashtags);
        }

        $followers = Arr::get($item, 'followersCount', Arr::get($item, 'authorStats.followerCount', Arr::get($item, 'followers')));
        $avgLikes = $this->averageFromLatestPosts($latestPosts, 'diggCount');
        $avgComments = $this->averageFromLatestPosts($latestPosts, 'commentCount');
        $engagementRate = '';
        if ((float) $followers > 0 && (((float) $avgLikes) > 0 || ((float) $avgComments) > 0)) {
            $engagementRate = round((((float) $avgLikes + (float) $avgComments) / (float) $followers) * 100, 2);
        }

        return [
            'id' => (string) (Arr::get($item, 'id', $username ?: md5($profileUrl))),
            'platform' => 'tiktok',
            'handle' => $this->normalizeHandle($username),
            'fullName' => $this->nullableString(Arr::get($item, 'nickname', Arr::get($item, 'authorMeta.nickName'))),
            'profileUrl' => $profileUrl,
            'followers' => $this->nullableInt($followers),
            'engagementRate' => $this->nullableFloat($engagementRate),
            'email' => $this->nullableString(Arr::get($item, 'email_from_bio', $this->extractEmailFromText((string) Arr::get($item, 'bio', Arr::get($item, 'signature', ''))))),
            'bio' => $this->nullableString(Arr::get($item, 'bio', Arr::get($item, 'signature'))),
            'postsCount' => $this->nullableInt(Arr::get($item, 'videoCount', Arr::get($item, 'authorStats.videoCount', Arr::get($item, 'posts')))),
            'avgLikes' => $this->nullableFloat($avgLikes),
            'avgComments' => $this->nullableFloat($avgComments),
            'isVerified' => $this->nullableBool(Arr::get($item, 'verified', Arr::get($item, 'authorMeta.verified', Arr::get($item, 'isVerified')))),
            'readyToMerge' => true,
            'sourceHashtags' => $sourceTags,
        ];
    }

    private function discoveryActorKey(string $platform): string
    {
        return $platform === 'instagram' ? 'instagram_discovery' : 'tiktok_discovery';
    }

    private function enrichmentActorKey(string $platform): string
    {
        return $platform === 'instagram' ? 'instagram_profile' : 'tiktok_profile';
    }

    private function rawSheetForPlatform(string $platform): string
    {
        return $platform === 'instagram' ? 'Instagram_Posts_Raw' : 'TikTok_Posts_Raw';
    }

    private function enrichedSheetForPlatform(string $platform): string
    {
        return $platform === 'instagram' ? 'Instagram_Profile_Enriched' : 'TikTok_Profile_Enriched';
    }

    private function discoveryInput(string $platform, array $hashtags, int $limit): array
    {
        return [
            'hashtags' => $hashtags,
            'keywordSearch' => false,
            'resultsLimit' => $limit,
            'resultsType' => $platform === 'instagram' ? 'reels' : 'reels',
        ];
    }

    private function enrichmentInput(string $platform, array $urls, array $hashtags, int $limit): array
    {
        if ($platform === 'instagram') {
            return [
                'addParentData' => false,
                'directUrls' => array_values($urls),
                'onlyPostsNewerThan' => '100 days',
                'resultsLimit' => max(1, min($limit, count($urls))),
                'resultsType' => 'details',
                'search' => $hashtags[0] ?? '',
                'searchLimit' => max(1, min($limit, count($urls))),
                'searchType' => 'hashtag',
            ];
        }

        $profiles = array_values(array_filter(array_map(function (string $url) {
            if (preg_match('~tiktok\.com/@([^/?#]+)~i', $url, $m)) {
                return $m[1];
            }
            return null;
        }, $urls)));

        return [
            'profiles' => $profiles,
            'excludePinnedPosts' => false,
            'shouldDownloadAvatars' => false,
            'shouldDownloadCovers' => false,
            'shouldDownloadSlideshowImages' => false,
            'shouldDownloadSubtitles' => false,
            'shouldDownloadVideos' => false,
        ];
    }

    private function profileUrlFor(string $platform, string $username, array $item): string
    {
        $candidate = trim((string) Arr::get($item, 'profileUrl', Arr::get($item, 'profile_url', Arr::get($item, 'inputUrl', Arr::get($item, 'input_url', Arr::get($item, 'url', ''))))));

        if ($platform === 'instagram') {
            if ($candidate !== '' && str_contains(strtolower($candidate), 'instagram.com/')) {
                if (preg_match('~instagram\.com/([^/?#]+)/?~i', $candidate, $m) && !in_array(strtolower($m[1]), ['p', 'reel', 'reels', 'tv', 'stories', 'explore'], true)) {
                    return 'https://www.instagram.com/' . trim($m[1]) . '/';
                }
            }
            $username = trim($username, '@/ ');
            return $username !== '' ? 'https://www.instagram.com/' . $username . '/' : '';
        }

        if ($candidate !== '' && str_contains(strtolower($candidate), 'tiktok.com/@')) {
            if (preg_match('~tiktok\.com/@([^/?#]+)~i', $candidate, $m)) {
                return 'https://www.tiktok.com/@' . trim($m[1]);
            }
        }
        $username = trim($username, '@/ ');
        return $username !== '' ? 'https://www.tiktok.com/@' . $username : '';
    }

    private function matchedHashtagsForItem(array $item, array $inputHashtags): array
    {
        $haystackParts = [];
        $hashtags = Arr::get($item, 'hashtags', []);
        if (is_array($hashtags)) {
            $haystackParts[] = implode(' ', $hashtags);
        }
        $haystackParts[] = (string) Arr::get($item, 'caption', Arr::get($item, 'text', Arr::get($item, 'desc', '')));
        $haystack = ' ' . strtolower(implode(' ', array_filter($haystackParts))) . ' ';

        $matches = [];
        foreach ($inputHashtags as $tag) {
            $needle = strtolower($this->normalizeHashtag($tag));
            if ($needle === '') {
                continue;
            }
            if (str_contains($haystack, '#' . strtolower($needle)) || preg_match('/\b' . preg_quote(strtolower($needle), '/') . '\b/', $haystack)) {
                $matches[] = $needle;
            }
        }

        return array_values(array_unique($matches));
    }

    private function normalizeHashtag(string $hashtag): string
    {
        return ltrim(trim($hashtag), '#');
    }

    private function normalizeHandle(string $username): string
    {
        $username = trim($username);
        if ($username === '') {
            return '';
        }
        return '@' . ltrim($username, '@');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric((string) $value)) {
            return null;
        }
        return (int) round((float) $value);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric((string) $value)) {
            return null;
        }
        return round((float) $value, 2);
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));
        return match ($normalized) {
            'true', '1', 'yes' => true,
            'false', '0', 'no' => false,
            default => null,
        };
    }

    private function extractEmailFromText(string $text): string
    {
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $matches)) {
            return $matches[0];
        }
        return '';
    }

    private function averageFromLatestPosts(array $posts, string $metric): string
    {
        if ($posts === [] || !is_array($posts)) {
            return '';
        }
        $values = [];
        foreach ($posts as $post) {
            $value = Arr::get($post, $metric, Arr::get($post, Str::snake($metric)));
            if (is_numeric((string) $value)) {
                $values[] = (float) $value;
            }
        }
        if ($values === []) {
            return '';
        }
        return (string) round(array_sum($values) / count($values), 2);
    }

    private function estimateInstagramEngagementRate(array $item): string
    {
        $followers = (float) Arr::get($item, 'followersCount', Arr::get($item, 'followers', 0));
        $latestPosts = Arr::get($item, 'latestPosts', Arr::get($item, 'latest_posts', []));
        $avgLikes = (float) $this->averageFromLatestPosts(is_array($latestPosts) ? $latestPosts : [], 'likesCount');
        $avgComments = (float) $this->averageFromLatestPosts(is_array($latestPosts) ? $latestPosts : [], 'commentsCount');

        if ($followers <= 0 || ($avgLikes <= 0 && $avgComments <= 0)) {
            return '';
        }

        return (string) round((($avgLikes + $avgComments) / $followers) * 100, 2);
    }
}
