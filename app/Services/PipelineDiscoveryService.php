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
        private DiscoveryCriteriaService $criteriaService,
        private ScraperRegistryService $scrapers,
        private InfluencerScoringService $scoring,
    ) {
    }

    public function estimate(
        string $planId,
        string $platform,
        int $discoveryLimit,
        int $enrichmentLimit,
        int $seedCount = 1,
        ?string $discoveryModuleKey = null,
        ?string $enrichmentModuleKey = null,
    ): array
    {
        return $this->scrapers->estimatePipeline(
            $planId,
            $platform,
            $discoveryLimit,
            $enrichmentLimit,
            $seedCount,
            $discoveryModuleKey,
            $enrichmentModuleKey,
        );
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
            'criteria' => $payload['criteria'] ?? null,
            'brief' => $payload['brief'] ?? null,
            'filterSummary' => null,
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
            'criteria' => $result['criteria'] ?? Arr::get($run->request_payload, 'criteria'),
            'filterSummary' => $result['filterSummary'] ?? null,
            'brief' => $result['brief'] ?? Arr::get($run->request_payload, 'brief'),
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
        $rankingMetric = $this->resolveRankingMetric($platform, (string) ($payload['rankingMetric'] ?? ''));
        $criteria = $this->normalizeDiscoveryCriteria(is_array($payload['criteria'] ?? null) ? $payload['criteria'] : []);
        $brief = trim((string) ($payload['brief'] ?? ''));
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
                'criteria' => $criteria,
                'brief' => $brief !== '' ? $brief : null,
            ]);

            $discovery = $this->discoveryProvider->discover($platform, $hashtags, $discoveryLimit, [
                'workspaceId' => $payload['workspaceId'] ?? null,
                'planId' => $payload['planId'] ?? 'free',
                'moduleKey' => $payload['discoveryModuleKey'] ?? null,
            ]);
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
            $this->persistDiscoveryItems($jobId, $projectId, $platform, $discoveryItems);

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
            $selectionPoolLimit = $this->resolveSelectionPoolLimit($enrichmentLimit, $criteria);
            [$profiles, $sourceHashtagsByUrl] = $this->selectProfilesFromRankedPosts(
                $platform,
                $discoveryItems,
                $hashtags,
                $selectionPoolLimit,
                $rankingMetric,
                $criteria
            );
            $crmMatchesByProfileUrl = $this->findExistingCrmMatches($projectId, $sheetId, $platform, $profiles);
            foreach ($profiles as &$profile) {
                $profileKey = $this->normalizeProfileUrlKey((string) ($profile['profileUrl'] ?? ''));
                $profile['alreadyInCrm'] = $profileKey !== '' && isset($crmMatchesByProfileUrl[$profileKey]);
            }
            unset($profile);
            Log::info('Pipeline extraction summary', [
                'jobId' => $jobId,
                'platform' => $platform,
                'rankingMetric' => $rankingMetric,
                'discoveryItemCount' => count($discoveryItems),
                'selectedProfileCount' => count($profiles),
                'sampleDiscoveryItems' => array_slice($discoveryItems, 0, 2),
                'sampleProfiles' => array_slice($profiles, 0, 5),
            ]);
            $stepResults[] = [
                'step' => 'extract_urls',
                'status' => 'completed',
                'rankingMetric' => $rankingMetric,
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
                    'criteria' => $criteria !== [] ? $criteria : null,
                    'filterSummary' => null,
                    'brief' => $brief !== '' ? $brief : null,
                ];

                $this->updateJob($jobId, [
                    'status' => 'completed',
                    'currentStep' => null,
                    'steps' => $stepResults,
                    'creators' => [],
                    'totalCreators' => 0,
                    'failedStep' => null,
                    'filterSummary' => null,
                    'result' => $final,
                ]);

                return $final;
            }

            $enrichmentJobId = $this->startEnrichmentJob($jobId, $projectId, $platform, array_column($profiles, 'profileUrl'));
            $this->updateJob($jobId, [
                'currentStep' => 'enrichment_scrape',
                'enrichmentJobId' => $enrichmentJobId,
            ]);

            $enrichment = $this->enrichmentProvider->enrich($platform, array_column($profiles, 'profileUrl'), $hashtags, $selectionPoolLimit, [
                'workspaceId' => $payload['workspaceId'] ?? null,
                'planId' => $payload['planId'] ?? 'free',
                'moduleKey' => $payload['enrichmentModuleKey'] ?? null,
            ]);
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
            $creators = $this->buildCreatorsResponse(
                $platform,
                $enrichmentItems,
                $this->profilesByProfileUrl($profiles),
                $sourceHashtagsByUrl,
                $hashtags,
                $criteria
            );
            $creators = $this->sortCreatorsByPriority($creators);
            $filterSummary = null;
            if ($criteria !== []) {
                $filtered = $this->criteriaService->apply($creators, $criteria);
                $creators = $this->shortlistCreatorsForOutput($filtered['creators'], $enrichmentLimit, $criteria);
                $refined = $this->criteriaService->apply($creators, $criteria);
                $creators = $refined['creators'];
                $filterSummary = $refined['summary'];
            } elseif ($enrichmentLimit > 0) {
                $creators = array_slice($creators, 0, $enrichmentLimit);
            }
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
                'criteria' => $criteria !== [] ? $criteria : null,
                'filterSummary' => $filterSummary,
                'brief' => $brief !== '' ? $brief : null,
            ];

            $this->updateJob($jobId, [
                'status' => 'completed',
                'currentStep' => null,
                'steps' => $stepResults,
                'creators' => $creators,
                'totalCreators' => count($creators),
                'failedStep' => null,
                'filterSummary' => $filterSummary,
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
                    'criteria' => $criteria !== [] ? $criteria : null,
                    'filterSummary' => null,
                    'brief' => $brief !== '' ? $brief : null,
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
        'criteria' => $state['criteria'] ?? Arr::get($state, 'request.criteria'),
        'filterSummary' => $state['filterSummary'] ?? null,
        'brief' => $state['brief'] ?? Arr::get($state, 'request.brief'),
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

private function selectProfilesFromRankedPosts(
        string $platform,
        array $items,
        array $inputHashtags,
        int $selectionLimit,
        string $rankingMetric,
        array $criteria = []
    ): array {
        $rankedPosts = [];
        $sourceHashtagsByUrl = [];
        $matchedPostCounts = [];

        foreach ($items as $index => $item) {
            if (!$this->passesDiscoveryThresholds($item, $criteria)) {
                continue;
            }

            $username = $this->extractUsernameFromDiscoveryItem($platform, $item);
            $profileUrl = $this->profileUrlFor($platform, $username, $item);

            if ($profileUrl === '') {
                continue;
            }

            $profileKey = $this->normalizeProfileUrlKey($profileUrl);
            $matchedTags = $this->matchedHashtagsForItem($item, $inputHashtags);
            $sourceHashtagsByUrl[$profileKey] = array_values(array_unique(array_merge(
                $sourceHashtagsByUrl[$profileKey] ?? [],
                $matchedTags
            )));
            $matchedPostCounts[$profileKey] = ($matchedPostCounts[$profileKey] ?? 0) + 1;

            $rankedPosts[] = [
                'platform' => $platform,
                'username' => $username,
                'handle' => $this->normalizeHandle($username),
                'profileUrl' => $profileUrl,
                'profileKey' => $profileKey,
                'postUrl' => $this->postUrlFor($platform, $item),
                'metricType' => $rankingMetric === 'none' ? null : $rankingMetric,
                'metricValue' => $rankingMetric === 'none' ? 0 : $this->metricValueForDiscoveryItem($platform, $item, $rankingMetric),
                'metrics' => [
                    'likes' => $this->nullableInt(Arr::get($item, 'likesCount', Arr::get($item, 'diggCount'))),
                    'comments' => $this->nullableInt(Arr::get($item, 'commentsCount', Arr::get($item, 'commentCount'))),
                    'views' => $this->nullableInt(Arr::get($item, 'playCount')),
                    'shares' => $this->nullableInt(Arr::get($item, 'shareCount')),
                ],
                'followerEstimate' => $this->extractDiscoveryFollowerEstimate($item),
                'rangeBucket' => $this->rangeBucketForFollowerEstimate($this->extractDiscoveryFollowerEstimate($item), $criteria),
                'originalIndex' => $index,
            ];
        }

        usort($rankedPosts, function (array $a, array $b) use ($rankingMetric) {
            $bucketCompare = ((int) ($a['rangeBucket'] ?? 9)) <=> ((int) ($b['rangeBucket'] ?? 9));
            if ($bucketCompare !== 0) {
                return $bucketCompare;
            }

            if ($rankingMetric !== 'none') {
                $scoreCompare = ((int) ($b['metricValue'] ?? 0)) <=> ((int) ($a['metricValue'] ?? 0));
                if ($scoreCompare !== 0) {
                    return $scoreCompare;
                }
            }

            return ((int) ($a['originalIndex'] ?? 0)) <=> ((int) ($b['originalIndex'] ?? 0));
        });

        $profiles = [];
        foreach ($rankedPosts as $post) {
            $profileKey = (string) $post['profileKey'];
            if ($profileKey === '' || isset($profiles[$profileKey])) {
                continue;
            }

            $profiles[$profileKey] = [
                'platform' => $platform,
                'handle' => $post['handle'],
                'username' => $post['username'],
                'profileUrl' => $post['profileUrl'],
                'sourcePostUrl' => $post['postUrl'] ?: null,
                'sourceMetricType' => $post['metricType'],
                'sourceMetricValue' => $rankingMetric === 'none' ? null : (int) ($post['metricValue'] ?? 0),
                'sourcePostMetrics' => $post['metrics'],
                'matchedPostCount' => (int) ($matchedPostCounts[$profileKey] ?? 1),
            ];

            if ($selectionLimit > 0 && count($profiles) >= $selectionLimit) {
                break;
            }
        }

        return [array_values($profiles), $sourceHashtagsByUrl];
    }

    private function extractUsernameFromDiscoveryItem(string $platform, array $item): string
    {
        if ($platform === 'instagram') {
            return trim((string) (
                Arr::get($item, 'ownerUsername')
                ?? Arr::get($item, 'owner.username')
                ?? Arr::get($item, 'user.username')
                ?? Arr::get($item, 'author.username')
                ?? Arr::get($item, 'username')
                ?? ''
            ));
        }

        return trim((string) (
            Arr::get($item, 'authorMeta.name')
            ?? Arr::get($item, 'author.uniqueId')
            ?? Arr::get($item, 'author.username')
            ?? Arr::get($item, 'authorMeta.uniqueId')
            ?? Arr::get($item, 'username')
            ?? ''
        ));
    }

    private function postUrlFor(string $platform, array $item): string
    {
        if ($platform === 'instagram') {
            return trim((string) Arr::get($item, 'url', Arr::get($item, 'postUrl', '')));
        }

        return trim((string) Arr::get($item, 'webVideoUrl', Arr::get($item, 'url', '')));
    }

    private function resolveRankingMetric(string $platform, string $requested): string
    {
        $requested = strtolower(trim($requested));

        if ($platform === 'tiktok') {
            return in_array($requested, ['none', 'views', 'likes', 'comments', 'shares'], true)
                ? $requested
                : 'views';
        }

        return in_array($requested, ['none', 'views', 'likes', 'comments'], true)
            ? $requested
            : 'likes';
    }

    private function metricValueForDiscoveryItem(string $platform, array $item, string $metric): int
    {
        return match ($metric) {
            'views' => (int) ($this->nullableInt(Arr::get($item, 'playCount')) ?? 0),
            'likes' => (int) ($this->nullableInt(Arr::get($item, 'likesCount', Arr::get($item, 'diggCount'))) ?? 0),
            'comments' => (int) ($this->nullableInt(Arr::get($item, 'commentsCount', Arr::get($item, 'commentCount'))) ?? 0),
            'shares' => (int) ($this->nullableInt(Arr::get($item, 'shareCount')) ?? 0),
            default => 0,
        };
    }

    private function resolveSelectionPoolLimit(int $enrichmentLimit, array $criteria = []): int
    {
        $base = max(1, $enrichmentLimit);
        $needsQualityBuffer = !empty($criteria['minimumLikes']) || !empty($criteria['minimumComments']) || !empty($criteria['minimumViews']);

        if (!$needsQualityBuffer) {
            return $base;
        }

        return min(max($base * 2, $base + 20), 120);
    }

    private function passesDiscoveryThresholds(array $item, array $criteria): bool
    {
        $minimumLikes = max(0, (int) ($criteria['minimumLikes'] ?? 0));
        $minimumComments = max(0, (int) ($criteria['minimumComments'] ?? 0));
        $minimumViews = max(0, (int) ($criteria['minimumViews'] ?? 0));

        $likes = (int) ($this->nullableInt(Arr::get($item, 'likesCount', Arr::get($item, 'diggCount'))) ?? 0);
        $comments = (int) ($this->nullableInt(Arr::get($item, 'commentsCount', Arr::get($item, 'commentCount'))) ?? 0);
        $views = (int) ($this->nullableInt(Arr::get($item, 'playCount')) ?? 0);
        $followerEstimate = $this->extractDiscoveryFollowerEstimate($item);
        $minimumFollowerFloor = $this->minimumFollowerFloor($criteria);

        if ($minimumFollowerFloor > 0 && $followerEstimate !== null && $followerEstimate < $minimumFollowerFloor) {
            return false;
        }

        if ($minimumLikes > 0 && $likes < $minimumLikes) {
            return false;
        }
        if ($minimumComments > 0 && $comments < $minimumComments) {
            return false;
        }
        if ($minimumViews > 0 && $views < $minimumViews) {
            return false;
        }

        return true;
    }

    private function extractDiscoveryFollowerEstimate(array $item): ?int
    {
        return $this->nullableInt(
            Arr::get($item, 'owner.followersCount', Arr::get($item, 'ownerFollowersCount', Arr::get($item, 'authorMeta.fans', Arr::get($item, 'authorStats.followerCount', Arr::get($item, 'followersCount', Arr::get($item, 'followers'))))))
        );
    }

    private function rangeBucketForFollowerEstimate(?int $followers, array $criteria): int
    {
        $minimumFollowerFloor = $this->minimumFollowerFloor($criteria);

        if ($minimumFollowerFloor <= 0) {
            return 0;
        }
        if ($followers === null) {
            return 1;
        }
        if ($followers < $minimumFollowerFloor) {
            return 3;
        }

        return 0;
    }

    private function shortlistCreatorsForOutput(array $creators, int $finalLimit, array $criteria = []): array
    {
        if ($finalLimit <= 0 || count($creators) <= $finalLimit) {
            return array_values($creators);
        }

        usort($creators, function (array $a, array $b) use ($criteria) {
            $rank = ['full' => 0, 'partial' => 1, 'weak' => 2];
            $catA = $rank[(string) ($a['matchCategory'] ?? 'weak')] ?? 9;
            $catB = $rank[(string) ($b['matchCategory'] ?? 'weak')] ?? 9;
            if ($catA !== $catB) {
                return $catA <=> $catB;
            }

            $priorityA = (float) ($a['priorityScore'] ?? 0);
            $priorityB = (float) ($b['priorityScore'] ?? 0);
            if ($priorityA !== $priorityB) {
                return $priorityB <=> $priorityA;
            }

            $distanceA = $this->followerDistanceFromRange((int) ($a['followers'] ?? 0), $criteria);
            $distanceB = $this->followerDistanceFromRange((int) ($b['followers'] ?? 0), $criteria);
            if ($distanceA !== $distanceB) {
                return $distanceA <=> $distanceB;
            }

            $scoreA = (float) ($a['fitScore'] ?? 0);
            $scoreB = (float) ($b['fitScore'] ?? 0);
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            $valueA = (float) ($a['valueScore'] ?? 0);
            $valueB = (float) ($b['valueScore'] ?? 0);
            if ($valueA !== $valueB) {
                return $valueB <=> $valueA;
            }

            return ((int) ($b['followers'] ?? 0)) <=> ((int) ($a['followers'] ?? 0));
        });

        return array_slice(array_values($creators), 0, $finalLimit);
    }

    private function sortCreatorsByPriority(array $creators): array
    {
        usort($creators, function (array $a, array $b) {
            $priorityA = (float) ($a['priorityScore'] ?? 0);
            $priorityB = (float) ($b['priorityScore'] ?? 0);
            if ($priorityA !== $priorityB) {
                return $priorityB <=> $priorityA;
            }

            $valueA = (float) ($a['valueScore'] ?? 0);
            $valueB = (float) ($b['valueScore'] ?? 0);
            if ($valueA !== $valueB) {
                return $valueB <=> $valueA;
            }

            return ((int) ($b['followers'] ?? 0)) <=> ((int) ($a['followers'] ?? 0));
        });

        return array_values($creators);
    }

    private function followerDistanceFromRange(int $followers, array $criteria): int
    {
        $min = max(0, (int) ($criteria['followerMin'] ?? 0));
        $max = max(0, (int) ($criteria['followerMax'] ?? 0));

        if ($min === 0 && $max === 0) {
            return 0;
        }
        if ($followers <= 0) {
            return 1000000000;
        }
        if (($min === 0 || $followers >= $min) && ($max === 0 || $followers <= $max)) {
            return 0;
        }
        if ($max > 0 && $followers > $max) {
            return $followers - $max;
        }
        if ($min > 0 && $followers < $min) {
            return $min - $followers;
        }

        return 1000000000;
    }

    private function normalizeDiscoveryCriteria(array $criteria): array
    {
        $includeSub1kCreators = (bool) ($criteria['includeSub1kCreators'] ?? false);
        $criteria['includeSub1kCreators'] = $includeSub1kCreators;
        $criteria['minimumFollowerFloor'] = $includeSub1kCreators
            ? 0
            : max(0, (int) ($criteria['minimumFollowerFloor'] ?? 1000));
        $criteria['followerMin'] = 0;
        $criteria['followerMax'] = 0;

        return $criteria;
    }

    private function minimumFollowerFloor(array $criteria): int
    {
        if ((bool) ($criteria['includeSub1kCreators'] ?? false)) {
            return 0;
        }

        return max(0, (int) ($criteria['minimumFollowerFloor'] ?? 1000));
    }

    private function passesCreatorFollowerFloor(array $creator, array $criteria): bool
    {
        $minimumFollowerFloor = $this->minimumFollowerFloor($criteria);
        if ($minimumFollowerFloor <= 0) {
            return true;
        }

        $followers = $this->nullableInt($creator['followers'] ?? null);

        return $followers !== null && $followers >= $minimumFollowerFloor;
    }

    private function normalizeProfileUrlKey(string $profileUrl): string
    {
        return strtolower(rtrim(trim($profileUrl), '/'));
    }

    private function findExistingCrmMatches(?int $projectId, string $sheetId, string $platform, array $profiles): array
    {
        $existing = [];

        if ($projectId) {
            $rows = \App\Models\CreatorProfile::query()
                ->where('project_id', $projectId)
                ->where('platform', strtolower($platform))
                ->get(['profile_url', 'dm_link', 'handle', 'username']);

            foreach ($rows as $row) {
                $profileUrl = $this->normalizeProfileUrlKey((string) ($row->profile_url ?? ''));
                $dmLink = $this->normalizeProfileUrlKey((string) ($row->dm_link ?? ''));
                $handle = strtolower(trim(ltrim((string) ($row->handle ?? $row->username ?? ''), '@')));
                if ($profileUrl !== '') {
                    $existing[$profileUrl] = true;
                }
                if ($dmLink !== '') {
                    $existing[$dmLink] = true;
                }
                if ($handle !== '') {
                    $existing['handle:' . $handle] = true;
                }
            }
        } elseif ($this->shouldSyncSheets($sheetId)) {
            $crmRows = $this->sheets->getRows($sheetId, 'Creators_CRM');
            foreach ($crmRows as $row) {
                $crmPlatform = strtolower(trim((string) ($row['Platform'] ?? '')));
                if ($crmPlatform !== strtolower($platform)) {
                    continue;
                }
                $dm = $this->normalizeProfileUrlKey((string) ($row['DM_Link'] ?? ''));
                $handle = strtolower(trim(ltrim((string) ($row['Handle'] ?? ''), '@')));
                if ($dm !== '') {
                    $existing[$dm] = true;
                }
                if ($handle !== '') {
                    $existing['handle:' . $handle] = true;
                }
            }
        }

        $matches = [];
        foreach ($profiles as $profile) {
            $profileKey = $this->normalizeProfileUrlKey((string) ($profile['profileUrl'] ?? ''));
            $handleKey = 'handle:' . strtolower(trim(ltrim((string) ($profile['handle'] ?? ''), '@')));
            if (($profileKey !== '' && isset($existing[$profileKey])) || ($handleKey !== 'handle:' && isset($existing[$handleKey]))) {
                if ($profileKey !== '') {
                    $matches[$profileKey] = true;
                }
            }
        }

        return $matches;
    }

    private function profilesByProfileUrl(array $profiles): array
    {
        $indexed = [];
        foreach ($profiles as $profile) {
            $profileKey = $this->normalizeProfileUrlKey((string) ($profile['profileUrl'] ?? ''));
            if ($profileKey === '') {
                continue;
            }
            $indexed[$profileKey] = $profile;
        }

        return $indexed;
    }

    private function buildCreatorsResponse(
        string $platform,
        array $enrichmentItems,
        array $selectedProfilesByUrl,
        array $sourceHashtagsByUrl,
        array $inputHashtags,
        array $criteria = []
    ): array
    {
        $creators = [];

        foreach ($enrichmentItems as $item) {
            $creator = $platform === 'instagram'
                ? $this->normalizeInstagramCreator($item, $selectedProfilesByUrl, $sourceHashtagsByUrl, $inputHashtags)
                : $this->normalizeTikTokCreator($item, $selectedProfilesByUrl, $sourceHashtagsByUrl, $inputHashtags);

            if ($creator !== null && !$this->passesCreatorFollowerFloor($creator, $criteria)) {
                $creator = null;
            }

            if ($creator !== null) {
                $scoreDetail = $this->scoring->detailedScore($creator, null, $criteria);
                $creator['valueScore'] = (int) ($scoreDetail['score'] ?? 0);
                $creator['valueTier'] = strtolower((string) ($scoreDetail['tier'] ?? $this->scoring->tier($creator['valueScore'])));
                $creator['valueSignals'] = $scoreDetail['signals'] ?? [];
                $creator['valueRisks'] = $scoreDetail['risks'] ?? [];
                $creator['priorityScore'] = (int) ($creator['valueScore'] ?? 0);
            }

            if ($creator === null) {
                continue;
            }

            $creators[$creator['profileUrl']] = $creator;
        }

        return array_values($creators);
    }

    private function normalizeInstagramCreator(
        array $item,
        array $selectedProfilesByUrl,
        array $sourceHashtagsByUrl,
        array $inputHashtags
    ): ?array {
        $username = trim((string) Arr::get($item, 'username', Arr::get($item, 'ownerUsername', '')));
        $profileUrl = $this->profileUrlFor('instagram', $username, $item);
        if ($profileUrl === '') {
            return null;
        }

        $latestPosts = Arr::get($item, 'latestPosts', Arr::get($item, 'latest_posts', []));
        $latestPosts = is_array($latestPosts) ? $latestPosts : [];
        $profileKey = $this->normalizeProfileUrlKey($profileUrl);
        $sourceTags = $sourceHashtagsByUrl[$profileKey] ?? [];
        if ($sourceTags == []) {
            $sourceTags = $this->matchedHashtagsForItem($item, $inputHashtags);
        }
        $selectedProfile = $selectedProfilesByUrl[$profileKey] ?? null;

$bio = (string) Arr::get($item, 'biography', Arr::get($item, 'bio', ''));
$fullName = (string) Arr::get($item, 'fullName', Arr::get($item, 'ownerFullName', ''));
$latestPostAt = $this->resolveLatestPostAt($latestPosts, 'instagram');
$locationHint = $this->resolveLocationHint($item, 'instagram');
$languageHints = $this->inferLanguageHints($bio . ' ' . $this->flattenLatestPostText($latestPosts));
$genderHint = $this->inferGenderHint($bio . ' ' . $fullName);

return [
    'id' => (string) (Arr::get($item, 'id', $username ?: md5($profileUrl))),
    'mergeRef' => 'instagram:source-url:' . rawurlencode(rtrim(strtolower($profileUrl), '/')),
    'platform' => 'instagram',
    'handle' => $this->normalizeHandle($username),
    'fullName' => $this->nullableString($fullName),
    'profileUrl' => $profileUrl,
    'avatarUrl' => $this->nullableString(
        Arr::get(
            $item,
            'profilePicUrl',
            Arr::get(
                $item,
                'profile_pic_url',
                Arr::get(
                    $item,
                    'profilePic',
                    Arr::get($item, 'profile_pic')
                )
            )
        )
    ),
    'followers' => $this->nullableInt(Arr::get($item, 'followersCount', Arr::get($item, 'followers'))),
    'engagementRate' => $this->nullableFloat($this->estimateInstagramEngagementRate($item)),
    'email' => $this->nullableString(Arr::get($item, 'email_from_bio', $this->extractEmailFromText($bio))),
    'bio' => $this->nullableString($bio),
    'postsCount' => $this->nullableInt(Arr::get($item, 'postsCount', Arr::get($item, 'posts_count'))),
    'avgLikes' => $this->nullableFloat($this->averageFromLatestPosts($latestPosts, 'likesCount')),
    'avgComments' => $this->nullableFloat($this->averageFromLatestPosts($latestPosts, 'commentsCount')),
    'isVerified' => $this->nullableBool(Arr::get($item, 'verified', Arr::get($item, 'is_verified'))),
    'latestPostAt' => $latestPostAt,
    'locationHint' => $locationHint,
    'languageHints' => $languageHints,
    'genderHint' => $genderHint,
    'nicheHints' => array_values(array_unique(array_slice(array_merge($sourceTags, $this->extractNicheHints($bio)), 0, 12))),
    'readyToMerge' => true,
    'sourceHashtags' => $sourceTags,
    'sourcePostUrl' => $selectedProfile['sourcePostUrl'] ?? null,
    'sourceMetricType' => $selectedProfile['sourceMetricType'] ?? null,
    'sourceMetricValue' => $selectedProfile['sourceMetricValue'] ?? null,
    'sourcePostMetrics' => $selectedProfile['sourcePostMetrics'] ?? null,
    'matchedPostCount' => $selectedProfile['matchedPostCount'] ?? 1,
    'alreadyInCrm' => (bool) ($selectedProfile['alreadyInCrm'] ?? false),
];
    }

    private function normalizeTikTokCreator(
        array $item,
        array $selectedProfilesByUrl,
        array $sourceHashtagsByUrl,
        array $inputHashtags
    ): ?array {
        $username = trim((string) Arr::get($item, 'username', Arr::get($item, 'authorMeta.name', Arr::get($item, 'author.username', ''))));
        $profileUrl = $this->profileUrlFor('tiktok', $username, $item);
        if ($profileUrl === '') {
            return null;
        }

        $latestPosts = Arr::get($item, 'latestPosts', Arr::get($item, 'latest_posts', []));
        $latestPosts = is_array($latestPosts) ? $latestPosts : [];
        $profileKey = $this->normalizeProfileUrlKey($profileUrl);
        $sourceTags = $sourceHashtagsByUrl[$profileKey] ?? [];
        if ($sourceTags == []) {
            $sourceTags = $this->matchedHashtagsForItem($item, $inputHashtags);
        }
        $selectedProfile = $selectedProfilesByUrl[$profileKey] ?? null;

        $followers = Arr::get($item, 'followersCount', Arr::get($item, 'authorStats.followerCount', Arr::get($item, 'followers')));
        $avgLikes = $this->averageFromLatestPosts($latestPosts, 'diggCount');
        $avgComments = $this->averageFromLatestPosts($latestPosts, 'commentCount');
        $engagementRate = '';
        if ((float) $followers > 0 && (((float) $avgLikes) > 0 || ((float) $avgComments) > 0)) {
            $engagementRate = round((((float) $avgLikes + (float) $avgComments) / (float) $followers) * 100, 2);
        }

$bio = (string) Arr::get($item, 'bio', Arr::get($item, 'signature', ''));
$fullName = (string) Arr::get($item, 'nickname', Arr::get($item, 'authorMeta.nickName', ''));
$latestPostAt = $this->resolveLatestPostAt($latestPosts, 'tiktok');
$locationHint = $this->resolveLocationHint($item, 'tiktok');
$languageHints = $this->inferLanguageHints($bio . ' ' . $this->flattenLatestPostText($latestPosts));
$genderHint = $this->inferGenderHint($bio . ' ' . $fullName);

return [
    'id' => (string) (Arr::get($item, 'id', $username ?: md5($profileUrl))),
    'mergeRef' => 'tiktok:source-url:' . rawurlencode(rtrim(strtolower($profileUrl), '/')),
    'platform' => 'tiktok',
    'handle' => $this->normalizeHandle($username),
    'fullName' => $this->nullableString($fullName),
    'profileUrl' => $profileUrl,
    'avatarUrl' => $this->nullableString(
        Arr::get(
            $item,
            'avatarUrl',
            Arr::get(
                $item,
                'avatar_url',
                Arr::get(
                    $item,
                    'avatarLarger',
                    Arr::get($item, 'authorMeta.avatar')
                )
            )
        )
    ),
    'followers' => $this->nullableInt($followers),
    'engagementRate' => $this->nullableFloat($engagementRate),
    'email' => $this->nullableString(Arr::get($item, 'email_from_bio', $this->extractEmailFromText($bio))),
    'bio' => $this->nullableString($bio),
    'postsCount' => $this->nullableInt(Arr::get($item, 'videoCount', Arr::get($item, 'authorStats.videoCount', Arr::get($item, 'posts')))),
    'avgLikes' => $this->nullableFloat($avgLikes),
    'avgComments' => $this->nullableFloat($avgComments),
    'isVerified' => $this->nullableBool(Arr::get($item, 'verified', Arr::get($item, 'authorMeta.verified', Arr::get($item, 'isVerified')))),
    'latestPostAt' => $latestPostAt,
    'locationHint' => $locationHint,
    'languageHints' => $languageHints,
    'genderHint' => $genderHint,
    'nicheHints' => array_values(array_unique(array_slice(array_merge($sourceTags, $this->extractNicheHints($bio)), 0, 12))),
    'readyToMerge' => true,
    'sourceHashtags' => $sourceTags,
    'sourcePostUrl' => $selectedProfile['sourcePostUrl'] ?? null,
    'sourceMetricType' => $selectedProfile['sourceMetricType'] ?? null,
    'sourceMetricValue' => $selectedProfile['sourceMetricValue'] ?? null,
    'sourcePostMetrics' => $selectedProfile['sourcePostMetrics'] ?? null,
    'matchedPostCount' => $selectedProfile['matchedPostCount'] ?? 1,
    'alreadyInCrm' => (bool) ($selectedProfile['alreadyInCrm'] ?? false),
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


    private function resolveLatestPostAt(array $latestPosts, string $platform): ?string
    {
        $candidates = [];
        foreach ($latestPosts as $post) {
            if (!is_array($post)) {
                continue;
            }
            $value = $platform === 'instagram'
                ? Arr::get($post, 'timestamp', Arr::get($post, 'takenAtTimestamp', Arr::get($post, 'createdAt')))
                : Arr::get($post, 'createTimeISO', Arr::get($post, 'createTime', Arr::get($post, 'timestamp')));

            if ($value === null || $value === '') {
                continue;
            }

            try {
                if (is_numeric((string) $value)) {
                    $timestamp = (int) $value;
                    if ($timestamp > 1000000000000) {
                        $timestamp = (int) floor($timestamp / 1000);
                    }
                    $candidates[] = date(DATE_ATOM, $timestamp);
                } else {
                    $candidates[] = (string) $value;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        rsort($candidates);

        return $candidates[0] ?? null;
    }

    private function resolveLocationHint(array $item, string $platform): ?string
    {
        $candidates = $platform === 'instagram'
            ? [
                Arr::get($item, 'addressCityName'),
                Arr::get($item, 'cityName'),
                Arr::get($item, 'businessAddress.cityName'),
                Arr::get($item, 'businessCategoryName'),
                Arr::get($item, 'location'),
            ]
            : [
                Arr::get($item, 'region'),
                Arr::get($item, 'locationCreated'),
                Arr::get($item, 'country'),
                Arr::get($item, 'countryCode'),
            ];

        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function flattenLatestPostText(array $latestPosts): string
    {
        $parts = [];
        foreach ($latestPosts as $post) {
            if (!is_array($post)) {
                continue;
            }
            $parts[] = (string) Arr::get($post, 'caption', Arr::get($post, 'text', Arr::get($post, 'desc', '')));
            $hashtags = Arr::get($post, 'hashtags', []);
            if (is_array($hashtags) && $hashtags !== []) {
                $parts[] = implode(' ', $hashtags);
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }

    private function inferLanguageHints(string $text): array
    {
        $text = ' ' . strtolower($text) . ' ';
        $hints = [];

        $germanSignals = [' und ', ' mit ', ' nicht ', ' liebe ', ' für ', ' ä', ' ö', ' ü', ' ß', ' de '];
        foreach ($germanSignals as $signal) {
            if (str_contains($text, $signal)) {
                $hints[] = 'de';
                break;
            }
        }

        $englishSignals = [' the ', ' and ', ' with ', ' for ', ' lifestyle ', ' beauty ', ' travel '];
        foreach ($englishSignals as $signal) {
            if (str_contains($text, $signal)) {
                $hints[] = 'en';
                break;
            }
        }

        return array_values(array_unique($hints));
    }

    private function inferGenderHint(string $text): ?string
    {
        $text = ' ' . strtolower($text) . ' ';
        $femaleSignals = [' she/her ', ' woman ', ' women ', ' girl ', ' female ', ' mama ', ' mom ', ' frau '];
        foreach ($femaleSignals as $signal) {
            if (str_contains($text, trim($signal))) {
                return 'female';
            }
        }

        $maleSignals = [' he/him ', ' man ', ' men ', ' boy ', ' male ', ' dad ', ' papa ', ' herr '];
        foreach ($maleSignals as $signal) {
            if (str_contains($text, trim($signal))) {
                return 'male';
            }
        }

        return null;
    }

    private function extractNicheHints(string $text): array
    {
        $text = strtolower($text);
        $dictionary = [
            'beauty', 'fashion', 'travel', 'fitness', 'food', 'wellness', 'parenting', 'gaming', 'tech', 'home', 'design', 'lifestyle', 'art', 'music', 'photography', 'books', 'couples', 'wedding', 'skincare', 'makeup'
        ];

        $matches = [];
        foreach ($dictionary as $keyword) {
            if (str_contains($text, $keyword)) {
                $matches[] = $keyword;
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
