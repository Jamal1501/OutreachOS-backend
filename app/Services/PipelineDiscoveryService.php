<?php

namespace App\Services;

use App\Contracts\DiscoveryProvider;
use App\Contracts\EnrichmentProvider;
use App\Models\DiscoveryItem;
use App\Models\DiscoveryRun;
use App\Models\EnrichmentJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PipelineDiscoveryService
{
    public function __construct(
        private ApifyRowMapper $rowMapper,
        private GoogleSheetsService $sheets,
        private DiscoveryProvider $discoveryProvider,
        private EnrichmentProvider $enrichmentProvider,
        private ProjectResolverService $projects,
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
            'projectId' => $this->pipelineSyncEnabled() ? $this->projects->resolveByWorkbookId((string) $payload['sheetId'])->id : null,
            'createdAt' => now()->toDateTimeString(),
            'updatedAt' => now()->toDateTimeString(),
        ];

        $this->writeJobState($jobId, $state);
        $this->syncJobToDatabase($state);

        return $state;
    }

public function getJobState(string $jobId): ?array
{
    // DB must be the source of truth in multi-container deployments like Render.
    $run = DiscoveryRun::query()->find($jobId);

    if ($run) {
        $result = is_array($run->result_payload) ? $run->result_payload : [];

        return [
            'jobId' => $run->id,
            'status' => $run->status,
            'currentStep' => $run->current_step,
            'completedSteps' => $result['completedSteps'] ?? [],
            'steps' => $result['steps'] ?? [],
            'creators' => $result['creators'] ?? [],
            'totalCreators' => $result['totalCreators'] ?? 0,
            'failedStep' => $run->error_message ? $run->current_step : null,
            'error' => $run->error_message,
            'projectId' => $run->project_id,
            'request' => $run->request_payload,
            'createdAt' => optional($run->created_at)?->toDateTimeString(),
            'updatedAt' => optional($run->updated_at)?->toDateTimeString(),
            'finishedAt' => optional($run->finished_at)?->toDateTimeString(),
        ];
    }

    // File fallback only for local/dev cases.
    $path = $this->jobPath($jobId);
    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

    public function runJob(string $jobId, array $payload): array
    
    {
        $sheetId = $payload['sheetId'];
        $platform = $payload['platform'];
        $hashtags = array_values(array_unique(array_filter(array_map(fn ($value) => $this->normalizeHashtag((string) $value), $payload['hashtags'] ?? []))));
        $discoveryLimit = (int) ($payload['discoveryLimit'] ?? 50);
        $enrichmentLimit = (int) ($payload['enrichmentLimit'] ?? 20);
        $dedupeAgainstCRM = (bool) ($payload['dedupeAgainstCRM'] ?? true);
$projectId = $this->pipelineSyncEnabled() ? $this->projects->resolveByWorkbookId($sheetId)->id : null;

        if ($projectId) {
            $run = DiscoveryRun::query()->find($jobId);
            if ($run && !$run->project_id) {
                $run->project_id = $projectId;
                $run->save();
            }
        }

        $stepResults = [];

        try {
            $this->updateJob($jobId, [
                'status' => 'running',
                'currentStep' => 'discovery_scrape',
                'projectId' => $projectId,
            ]);

            $discovery = $this->discoveryProvider->discover($platform, $hashtags, $discoveryLimit);
            $discoveryItems = $discovery->items;
            $stepResults[] = [
                'step' => 'discovery_scrape',
                'status' => 'completed',
                'provider' => $discovery->provider,
                'runId' => $discovery->runId,
                'datasetId' => $discovery->datasetId,
                'itemCount' => $discovery->itemCount(),
            ];
            $this->completeStep($jobId, 'discovery_scrape', end($stepResults));
            $this->persistDiscoveryProviderResult($jobId, $projectId, $platform, $hashtags, $discoveryLimit, $enrichmentLimit, $dedupeAgainstCRM, $discovery);
           // $this->persistDiscoveryItems($jobId, $projectId, $platform, $discoveryItems);

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
            Log::info('Pipeline extraction summary', [
    'jobId' => $jobId,
    'platform' => $platform,
    'discoveryItemCount' => count($discoveryItems),
    'extractedProfileCount' => count($profiles),
    'sampleDiscoveryItems' => array_slice($discoveryItems, 0, 2),
    'sampleProfiles' => array_slice($profiles, 0, 5),
]);
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
            $this->markDiscoveryProfilesPromoted($jobId, $projectId, array_column($profiles, 'profileUrl'));

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

            $enrichmentJobId = $this->startEnrichmentJob($jobId, $projectId, $platform, array_column($profiles, 'profileUrl'));
            $this->updateJob($jobId, [
                'currentStep' => 'enrichment_scrape',
                'enrichmentJobId' => $enrichmentJobId,
            ]);

            $enrichment = $this->enrichmentProvider->enrich($platform, array_column($profiles, 'profileUrl'), $hashtags, $enrichmentLimit);
            $enrichmentItems = $enrichment->items;
            $stepResults[] = [
                'step' => 'enrichment_scrape',
                'status' => 'completed',
                'provider' => $enrichment->provider,
                'runId' => $enrichment->runId,
                'datasetId' => $enrichment->datasetId,
                'itemCount' => $enrichment->itemCount(),
            ];
            $this->completeStep($jobId, 'enrichment_scrape', end($stepResults));
            $this->finishEnrichmentJob($enrichmentJobId, $enrichment);

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
        } catch (\Throwable $exception) {
            Log::error('Pipeline discovery job failed', [
                'jobId' => $jobId,
                'message' => $exception->getMessage(),
            ]);

            $state = $this->getJobState($jobId) ?? [];
            $failedStep = $state['currentStep'] ?? null;

            if (!empty($state['enrichmentJobId'])) {
                $this->failEnrichmentJob((string) $state['enrichmentJobId'], $exception->getMessage());
            }

            $this->updateJob($jobId, [
                'status' => 'failed',
                'failedStep' => $failedStep,
                'error' => $exception->getMessage(),
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

            throw $exception;
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
        $this->syncJobToDatabase($state);
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

    private function pipelineSyncEnabled(): bool
    {
        return config('outreach.operational_db.mode', 'dual') !== 'off'
            && config('outreach.sync.pipeline', true);
    }

    private function syncJobToDatabase(array $state): void
    {
        if (!$this->pipelineSyncEnabled() || empty($state['projectId']) || empty($state['jobId'])) {
            return;
        }

        $run = DiscoveryRun::query()->find((string) $state['jobId']);
        if (!$run) {
            $run = new DiscoveryRun();
            $run->id = (string) $state['jobId'];
        }

        $run->project_id = (int) $state['projectId'];
        $run->platform = (string) Arr::get($state, 'request.platform', $run->platform ?: 'instagram');
        $run->provider = (string) Arr::get($state, 'provider', $run->provider ?: config('outreach.providers.discovery', 'apify'));
        $run->status = (string) ($state['status'] ?? $run->status ?: 'running');
        $run->current_step = $state['currentStep'] ?? null;
        $run->hashtags = Arr::get($state, 'request.hashtags', []);
        $run->discovery_limit = (int) Arr::get($state, 'request.discoveryLimit', 50);
        $run->enrichment_limit = (int) Arr::get($state, 'request.enrichmentLimit', 20);
        $run->dedupe_against_crm = (bool) Arr::get($state, 'request.dedupeAgainstCRM', true);
        $run->request_payload = $state['request'] ?? null;
        $run->result_payload = array_merge(
    [
        'completedSteps' => $state['completedSteps'] ?? [],
        'steps' => $state['steps'] ?? [],
        'creators' => $state['creators'] ?? [],
        'totalCreators' => $state['totalCreators'] ?? 0,
        'failedStep' => $state['failedStep'] ?? null,
    ],
    is_array($state['result'] ?? null) ? $state['result'] : []
);
        $run->error_message = $state['error'] ?? null;
        $run->started_at = $run->started_at ?: now();
        if (($state['status'] ?? null) === 'completed' || ($state['status'] ?? null) === 'failed') {
            $run->finished_at = now();
        }
        $run->save();
    }

    private function persistDiscoveryProviderResult(
        string $jobId,
        ?int $projectId,
        string $platform,
        array $hashtags,
        int $discoveryLimit,
        int $enrichmentLimit,
        bool $dedupeAgainstCrm,
        object $discovery,
    ): void {
        if (!$this->pipelineSyncEnabled() || !$projectId) {
            return;
        }

        $run = DiscoveryRun::query()->find($jobId);
        if (!$run) {
            return;
        }

        $run->platform = $platform;
        $run->provider = (string) ($discovery->provider ?? config('outreach.providers.discovery', 'apify'));
        $run->provider_run_id = $discovery->runId ?? null;
        $run->provider_dataset_id = $discovery->datasetId ?? null;
        $run->hashtags = $hashtags;
        $run->discovery_limit = $discoveryLimit;
        $run->enrichment_limit = $enrichmentLimit;
        $run->dedupe_against_crm = $dedupeAgainstCrm;
        $run->save();
    }

    private function persistDiscoveryItems(string $jobId, ?int $projectId, string $platform, array $items): void
    {
        if (!$this->pipelineSyncEnabled() || !$projectId) {
            return;
        }

        foreach ($items as $item) {
            $username = trim((string) ($platform === 'instagram'
                ? Arr::get($item, 'ownerUsername', Arr::get($item, 'owner.username', Arr::get($item, 'username', '')))
                : Arr::get($item, 'authorMeta.name', Arr::get($item, 'author.username', Arr::get($item, 'username', '')))));
            $profileUrl = $this->profileUrlFor($platform, $username, $item);
            $postUrl = trim((string) ($platform === 'instagram'
                ? Arr::get($item, 'url', Arr::get($item, 'postUrl', ''))
                : Arr::get($item, 'webVideoUrl', Arr::get($item, 'url', ''))));
            $caption = trim((string) Arr::get($item, 'caption', Arr::get($item, 'text', '')));
            $hashtags = $this->matchedHashtagsForItem($item, []);
            $duplicateKey = strtolower($platform . '|' . ($username !== '' ? ltrim($this->normalizeHandle($username), '@') : $postUrl));

            if ($profileUrl === '' && $postUrl === '' && $caption === '') {
                continue;
            }

            DiscoveryItem::updateOrCreate(
                [
                    'project_id' => $projectId,
                    'discovery_run_id' => $jobId,
                    'platform' => $platform,
                    'post_url' => $postUrl !== '' ? $postUrl : ('payload:' . md5(json_encode($item))),
                ],
                [
                    'external_post_id' => (string) Arr::get($item, 'id', Arr::get($item, 'postId', '')) ?: null,
                    'handle' => $this->normalizeHandle($username) ?: null,
                    'username' => $username !== '' ? ltrim($username, '@') : null,
                    'full_name' => $this->nullableString((string) Arr::get($item, 'ownerFullName', Arr::get($item, 'fullName', ''))),
                    'profile_url' => $profileUrl ?: null,
                    'caption' => $caption ?: null,
                    'hashtags' => $hashtags,
                    'metrics' => array_filter([
                        'likes' => $this->nullableInt(Arr::get($item, 'likesCount', Arr::get($item, 'diggCount'))),
                        'comments' => $this->nullableInt(Arr::get($item, 'commentsCount', Arr::get($item, 'commentCount'))),
                        'views' => $this->nullableInt(Arr::get($item, 'playCount')),
                        'shares' => $this->nullableInt(Arr::get($item, 'shareCount')),
                    ], fn ($value) => $value !== null),
                    'duplicate_key' => $duplicateKey,
                    'raw_payload' => $item,
                    'discovered_at' => now(),
                ],
            );
        }
    }

    private function markDiscoveryProfilesPromoted(string $jobId, ?int $projectId, array $profileUrls): void
    {
        if (!$this->pipelineSyncEnabled() || !$projectId || $profileUrls === []) {
            return;
        }

        DiscoveryItem::query()
            ->where('project_id', $projectId)
            ->where('discovery_run_id', $jobId)
            ->whereIn('profile_url', array_values(array_filter($profileUrls)))
            ->update(['promoted_to_enrichment_at' => now()]);
    }

    private function startEnrichmentJob(string $jobId, ?int $projectId, string $platform, array $inputUrls): ?string
    {
        if (!$this->pipelineSyncEnabled() || !$projectId) {
            return null;
        }

        $job = new EnrichmentJob();
        $job->project_id = $projectId;
        $job->discovery_run_id = $jobId;
        $job->platform = $platform;
        $job->provider = (string) config('outreach.providers.enrichment', 'apify');
        $job->status = 'running';
        $job->input_urls = array_values(array_filter($inputUrls));
        $job->request_payload = ['profile_urls' => array_values(array_filter($inputUrls))];
        $job->started_at = now();
        $job->save();

        return $job->id;
    }

    private function finishEnrichmentJob(?string $enrichmentJobId, object $enrichment): void
    {
        if (!$this->pipelineSyncEnabled() || !$enrichmentJobId) {
            return;
        }

        $job = EnrichmentJob::query()->find($enrichmentJobId);
        if (!$job) {
            return;
        }

        $job->provider = (string) ($enrichment->provider ?? $job->provider);
        $job->provider_run_id = $enrichment->runId ?? null;
        $job->provider_dataset_id = $enrichment->datasetId ?? null;
        $job->status = 'completed';
        $job->result_payload = ['item_count' => $enrichment->itemCount()];
        $job->finished_at = now();
        $job->save();
    }

    private function failEnrichmentJob(?string $enrichmentJobId, string $message): void
    {
        if (!$this->pipelineSyncEnabled() || !$enrichmentJobId) {
            return;
        }

        $job = EnrichmentJob::query()->find($enrichmentJobId);
        if (!$job) {
            return;
        }

        $job->status = 'failed';
        $job->error_message = $message;
        $job->finished_at = now();
        $job->save();
    }

    private function appendRowsIfAny(string $sheetId, string $sheetName, array $rows): int
    {
        if (count($rows) === 0) {
            return 0;
        }

        if (!$this->shouldSyncSheets($sheetId)) {
            return 0;
        }

        $this->sheets->appendRows($sheetId, $sheetName, $rows);
        return count($rows);
    }

    private function shouldSyncSheets(string $sheetId): bool
    {
        return !str_starts_with($sheetId, 'workspace:') && !str_starts_with($sheetId, 'db:');
    }

private function extractProfilesFromDiscoveryItems(string $platform, array $items, array $inputHashtags): array
{
    $profiles = [];
    $sourceHashtagsByUrl = [];

    foreach ($items as $item) {
        $username = '';

        if ($platform === 'instagram') {
            $username = trim((string) (
                Arr::get($item, 'ownerUsername')
                ?? Arr::get($item, 'owner.username')
                ?? Arr::get($item, 'user.username')
                ?? Arr::get($item, 'author.username')
                ?? Arr::get($item, 'username')
                ?? ''
            ));
        } else {
            $username = trim((string) (
                Arr::get($item, 'authorMeta.name')
                ?? Arr::get($item, 'authorMeta.nickName')
                ?? Arr::get($item, 'author.username')
                ?? Arr::get($item, 'author.uniqueId')
                ?? Arr::get($item, 'authorMeta.uniqueId')
                ?? Arr::get($item, 'username')
                ?? ''
            ));
        }

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
        if (!$this->shouldSyncSheets($sheetId)) {
            return $profiles;
        }

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
            'mergeRef' => 'instagram:source-url:' . rawurlencode(rtrim(strtolower($profileUrl), '/')),
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
            'mergeRef' => 'instagram:source-url:' . rawurlencode(rtrim(strtolower($profileUrl), '/')),
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

    private function rawSheetForPlatform(string $platform): string
    {
        return $platform === 'instagram' ? 'Instagram_Posts_Raw' : 'TikTok_Posts_Raw';
    }

    private function enrichedSheetForPlatform(string $platform): string
    {
        return $platform === 'instagram' ? 'Instagram_Profile_Enriched' : 'TikTok_Profile_Enriched';
    }

    private function profileUrlFor(string $platform, string $username, array $item): string
    {
        $candidate = trim((string) Arr::get($item, 'profileUrl', Arr::get($item, 'profile_url', Arr::get($item, 'inputUrl', Arr::get($item, 'input_url', Arr::get($item, 'url', ''))))));

        if ($platform === 'instagram') {
            if ($candidate !== '' && str_contains(strtolower($candidate), 'instagram.com/')) {
                if (preg_match('~instagram\.com/([^/?#]+)/?~i', $candidate, $matches) && !in_array(strtolower($matches[1]), ['p', 'reel', 'reels', 'tv', 'stories', 'explore'], true)) {
                    return 'https://www.instagram.com/' . trim($matches[1]) . '/';
                }
            }
            $username = trim($username, '@/ ');
            return $username !== '' ? 'https://www.instagram.com/' . $username . '/' : '';
        }

        if ($candidate !== '' && str_contains(strtolower($candidate), 'tiktok.com/@')) {
            if (preg_match('~tiktok\.com/@([^/?#]+)~i', $candidate, $matches)) {
                return 'https://www.tiktok.com/@' . trim($matches[1]);
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
