<?php

namespace App\Http\Controllers;

use App\Jobs\RunPipelineJob;
use App\Models\DiscoveryRun;
use App\Services\AiDiscoveryBriefService;
use App\Services\PipelineDiscoveryService;
use App\Services\WorkspaceBillingService;
use App\Services\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PipelineController extends Controller
{
    public function __construct(
        private PipelineDiscoveryService $pipeline,
        private WorkspaceContextService $workspaceContext,
        private AiDiscoveryBriefService $briefs,
        private WorkspaceBillingService $billing,
    ) {
    }

    public function discover(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'platform' => ['required', 'string', Rule::in($this->pilotPlatforms())],
            'hashtags' => ['required', 'array', 'min:1', 'max:20'],
            'hashtags.*' => ['string', 'max:80'],
            'discoveryLimit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'enrichmentLimit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'dedupeAgainstCRM' => ['nullable', 'boolean'],
            'rankingMetric' => ['nullable', 'string', Rule::in(['none', 'views_desc', 'views_asc', 'likes_desc', 'likes_asc', 'comments_desc', 'comments_asc', 'shares_desc', 'shares_asc'])],
            'wait' => ['nullable', 'boolean'],
            'brief' => ['nullable', 'string', 'max:5000'],
            'criteria' => ['nullable', 'array', 'max:100'],
            'discoveryModuleKey' => ['nullable', 'string', 'max:120'],
            'enrichmentModuleKey' => ['nullable', 'string', 'max:120'],
            'clientJobId' => ['nullable', 'uuid'],
        ]);

        $criteria = $this->normalizeDiscoveryCriteria((array) ($validated['criteria'] ?? []));

        $payload = [
            'sheetId' => $this->resolveSheetId($request, $validated['sheetId'] ?? null),
            'platform' => $validated['platform'],
            'hashtags' => $validated['hashtags'],
            'discoveryLimit' => (int) ($validated['discoveryLimit'] ?? 200),
            'enrichmentLimit' => (int) ($validated['enrichmentLimit'] ?? 50),
            'dedupeAgainstCRM' => (bool) ($validated['dedupeAgainstCRM'] ?? true),
            'rankingMetric' => (string) ($validated['rankingMetric'] ?? ''),
            'brief' => $validated['brief'] ?? null,
            'criteria' => $criteria !== [] ? $criteria : null,
            'discoveryModuleKey' => $validated['discoveryModuleKey'] ?? null,
            'enrichmentModuleKey' => $validated['enrichmentModuleKey'] ?? null,
            'clientJobId' => $validated['clientJobId'] ?? null,
        ];

        return $this->startPipeline($request, $payload);
    }

    public function discoverFromBrief(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'platform' => ['required', 'string', Rule::in($this->pilotPlatforms())],
            'brief' => ['required', 'string', 'min:8', 'max:5000'],
            'projectContext' => ['nullable', 'string', 'max:5000'],
            'discoveryLimit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'enrichmentLimit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'dedupeAgainstCRM' => ['nullable', 'boolean'],
            'rankingMetric' => ['nullable', 'string', Rule::in(['none', 'views_desc', 'views_asc', 'likes_desc', 'likes_asc', 'comments_desc', 'comments_asc', 'shares_desc', 'shares_asc'])],
            'wait' => ['nullable', 'boolean'],
            'discoveryModuleKey' => ['nullable', 'string', 'max:120'],
            'enrichmentModuleKey' => ['nullable', 'string', 'max:120'],
            'clientJobId' => ['nullable', 'uuid'],
        ]);

        $criteria = $this->normalizeDiscoveryCriteria($this->briefs->parse((string) $validated['brief'], [
            'platform' => $validated['platform'],
            'projectContext' => $validated['projectContext'] ?? null,
        ]));

        $payload = [
            'sheetId' => $this->resolveSheetId($request, $validated['sheetId'] ?? null),
            'platform' => $validated['platform'],
            'hashtags' => $criteria['hashtags'] ?? [],
            'discoveryLimit' => (int) ($validated['discoveryLimit'] ?? 200),
            'enrichmentLimit' => (int) ($validated['enrichmentLimit'] ?? 50),
            'dedupeAgainstCRM' => (bool) ($validated['dedupeAgainstCRM'] ?? true),
            'rankingMetric' => (string) ($validated['rankingMetric'] ?? ''),
            'brief' => (string) $validated['brief'],
            'criteria' => $criteria,
            'discoveryModuleKey' => $validated['discoveryModuleKey'] ?? null,
            'enrichmentModuleKey' => $validated['enrichmentModuleKey'] ?? null,
            'clientJobId' => $validated['clientJobId'] ?? null,
        ];

        return $this->startPipeline($request, $payload, [
            'criteria' => $criteria,
            'discoveryModuleKey' => $validated['discoveryModuleKey'] ?? null,
            'enrichmentModuleKey' => $validated['enrichmentModuleKey'] ?? null,
        ]);
    }

    public function estimate(Request $request)
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', Rule::in($this->pilotPlatforms())],
            'discoveryLimit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'enrichmentLimit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'hashtags' => ['nullable', 'array', 'max:20'],
            'hashtags.*' => ['string', 'max:80'],
            'seedCount' => ['nullable', 'integer', 'min:1', 'max:50'],
            'discoveryModuleKey' => ['nullable', 'string', 'max:120'],
            'enrichmentModuleKey' => ['nullable', 'string', 'max:120'],
        ]);

        $workspaceId = (string) $request->attributes->get('workspace_id');
        $seedCount = max(1, count((array) ($validated['hashtags'] ?? [])) ?: (int) ($validated['seedCount'] ?? 1));

        $preflight = $this->creditPreflight($workspaceId, [
            'platform' => (string) $validated['platform'],
            'discoveryLimit' => (int) ($validated['discoveryLimit'] ?? 200),
            'enrichmentLimit' => (int) ($validated['enrichmentLimit'] ?? 50),
            'seedCount' => $seedCount,
            'discoveryModuleKey' => $validated['discoveryModuleKey'] ?? null,
            'enrichmentModuleKey' => $validated['enrichmentModuleKey'] ?? null,
        ]);

        return response()->json([
            'message' => 'Pipeline estimate calculated',
            'data' => $preflight,
        ]);
    }

    public function status(Request $request)
    {
        $validated = $request->validate([
            'jobId' => ['required', 'string'],
        ]);

        $state = $this->pipeline->getJobState($validated['jobId']);
        if (!$state) {
            return response()->json([
                'error' => 'Pipeline job not found',
            ], 404);
        }
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $jobWorkspaceId = (string) data_get($state, 'request.workspaceId', '');
        if ($jobWorkspaceId !== '' && $workspaceId !== '' && $jobWorkspaceId !== $workspaceId) {
            return response()->json([
                'error' => 'Pipeline job not found',
            ], 404);
        }
        $state = $this->pipeline->reconcileStaleJob($validated['jobId'], $state) ?? $state;

        return response()->json([
            'jobId' => $state['jobId'],
            'status' => $state['status'] ?? 'running',
            'currentStep' => $state['currentStep'] ?? null,
            'completedSteps' => $state['completedSteps'] ?? [],
            'creators' => $state['creators'] ?? [],
            'totalCreators' => $state['totalCreators'] ?? 0,
            'failedStep' => $state['failedStep'] ?? null,
            'steps' => $state['steps'] ?? [],
            'progress' => $this->progressSnapshot($state),
            'error' => $state['error'] ?? null,
            'criteria' => $state['criteria'] ?? null,
            'filterSummary' => $state['filterSummary'] ?? null,
            'brief' => $state['brief'] ?? null,
            'usageSummary' => $state['usageSummary'] ?? null,
        ]);
    }

    public function cancel(Request $request)
    {
        $validated = $request->validate([
            'jobId' => ['nullable', 'uuid'],
        ]);

        $workspaceId = (string) $request->attributes->get('workspace_id');
        $jobId = trim((string) ($validated['jobId'] ?? ''));
        if ($jobId === '') {
            $jobId = (string) (DiscoveryRun::query()
                ->whereIn('status', ['running', 'cancel_requested'])
                ->whereHas('project', fn ($query) => $query->where('workspace_id', $workspaceId))
                ->latest('created_at')
                ->value('id') ?? '');
        }

        $state = $jobId !== '' ? $this->pipeline->getJobState($jobId) : null;
        $jobWorkspaceId = (string) data_get($state, 'request.workspaceId', '');
        if (!$state || ($jobWorkspaceId !== '' && $workspaceId !== '' && $jobWorkspaceId !== $workspaceId)) {
            return response()->json(['error' => 'Pipeline job not found'], 404);
        }

        $state = $this->pipeline->requestCancellation($jobId);

        return response()->json([
            'jobId' => $state['jobId'],
            'status' => $state['status'] ?? 'cancel_requested',
            'message' => 'Stop requested. The active provider run is being cancelled.',
        ], 202);
    }

    private function progressSnapshot(array $state): array
    {
        $request = is_array($state['request'] ?? null) ? $state['request'] : [];
        $steps = is_array($state['steps'] ?? null) ? $state['steps'] : [];
        $completedSteps = is_array($state['completedSteps'] ?? null) ? $state['completedSteps'] : [];
        $currentStep = (string) ($state['currentStep'] ?? '');
        $status = (string) ($state['status'] ?? 'running');
        $creators = is_array($state['creators'] ?? null) ? $state['creators'] : [];
        $hashtags = array_values(array_filter((array) ($request['hashtags'] ?? []), fn ($value) => trim((string) $value) !== ''));
        $seedCount = max(1, count($hashtags));
        $discoveryLimit = max(1, (int) ($request['discoveryLimit'] ?? 200));
        $enrichmentLimit = max(1, (int) ($request['enrichmentLimit'] ?? 50));

        $stepPayloads = [];
        foreach ($steps as $step) {
            if (is_array($step) && isset($step['step'])) {
                $stepPayloads[(string) $step['step']] = $step;
            }
        }

        $foundPosts = $this->numericProgressValue($stepPayloads['discovery_scrape']['itemCount'] ?? null);
        $importedPosts = $this->numericProgressValue($stepPayloads['import_posts']['importedRows'] ?? null);
        $uniqueProfiles = $this->numericProgressValue($stepPayloads['extract_urls']['uniqueProfiles'] ?? null);
        $enrichedProfiles = $this->numericProgressValue($stepPayloads['enrichment_scrape']['itemCount'] ?? null);
        $enrichmentProgress = is_array($state['enrichmentProgress'] ?? null) ? $state['enrichmentProgress'] : [];
        $batchedEnrichedProfiles = $this->numericProgressValue($enrichmentProgress['completedProfiles'] ?? null);
        $batchedTotalProfiles = $this->numericProgressValue($enrichmentProgress['totalProfiles'] ?? null);
        $readyCreators = $status === 'completed'
            ? (int) ($state['totalCreators'] ?? count($creators))
            : null;

        $stages = [
            [
                'key' => 'discovery_scrape',
                'label' => 'Finding public creator signals',
                'status' => $this->progressStageStatus('discovery_scrape', $currentStep, $completedSteps, $status),
                'detail' => $foundPosts !== null
                    ? $foundPosts . ' posts found'
                    : 'Searching ' . $seedCount . ' hashtag' . ($seedCount === 1 ? '' : 's') . ' with Apify',
                'count' => $foundPosts,
            ],
            [
                'key' => 'import_posts',
                'label' => 'Processing discovered posts',
                'status' => $this->progressStageStatus('import_posts', $currentStep, $completedSteps, $status),
                'detail' => $importedPosts !== null
                    ? $importedPosts . ' posts processed'
                    : 'Preparing discovered posts for profile extraction',
                'count' => $importedPosts,
            ],
            [
                'key' => 'extract_urls',
                'label' => 'Selecting creator profiles',
                'status' => $this->progressStageStatus('extract_urls', $currentStep, $completedSteps, $status),
                'detail' => $uniqueProfiles !== null
                    ? $uniqueProfiles . ' unique profiles selected'
                    : 'Ranking posts and removing duplicate profile URLs',
                'count' => $uniqueProfiles,
            ],
            [
                'key' => 'enrichment_scrape',
                'label' => 'Enriching selected profiles',
                'status' => $this->progressStageStatus('enrichment_scrape', $currentStep, $completedSteps, $status),
                'detail' => $enrichedProfiles !== null
                    ? $enrichedProfiles . ' profiles enriched'
                    : ($batchedEnrichedProfiles !== null && $batchedTotalProfiles !== null
                        ? $batchedEnrichedProfiles . ' of ' . $batchedTotalProfiles . ' profiles enriched'
                        : 'Enriching up to ' . min($enrichmentLimit, max($uniqueProfiles ?? $enrichmentLimit, 1)) . ' selected profiles'
                    ),
                'count' => $enrichedProfiles ?? $batchedEnrichedProfiles,
            ],
            [
                'key' => 'import_profiles',
                'label' => 'Preparing review queue',
                'status' => $this->progressStageStatus('import_profiles', $currentStep, $completedSteps, $status),
                'detail' => $readyCreators !== null
                    ? $readyCreators . ' creators ready to review'
                    : 'Scoring fit and preparing the shortlist',
                'count' => $readyCreators,
            ],
        ];

        return [
            'stages' => $stages,
            'counters' => [
                'requestedPosts' => $discoveryLimit,
                'requestedProfiles' => $enrichmentLimit,
                'seedCount' => $seedCount,
                'foundPosts' => $foundPosts,
                'processedPosts' => $importedPosts,
                'uniqueProfiles' => $uniqueProfiles,
                'previewCreators' => count($creators),
                'enrichedProfiles' => $enrichedProfiles,
                'readyCreators' => $readyCreators,
            ],
            'canPreviewCreators' => $status === 'running' && count($creators) > 0,
            'message' => $this->progressMessage($currentStep, $status, count($creators)),
        ];
    }

    private function progressStageStatus(string $step, string $currentStep, array $completedSteps, string $pipelineStatus): string
    {
        if ($pipelineStatus === 'completed' || in_array($step, $completedSteps, true)) {
            return 'completed';
        }

        if ($pipelineStatus === 'failed' && $step === $currentStep) {
            return 'failed';
        }

        return $step === $currentStep ? 'running' : 'queued';
    }

    private function numericProgressValue(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function progressMessage(string $currentStep, string $status, int $previewCount): string
    {
        if ($status === 'completed') {
            return 'Shortlist is ready for review.';
        }

        if ($status === 'failed') {
            return 'Discovery stopped before the shortlist was ready.';
        }

        if ($previewCount > 0) {
            return $previewCount . ' creator preview' . ($previewCount === 1 ? ' is' : 's are') . ' ready while enrichment continues.';
        }

        return match ($currentStep) {
            'discovery_scrape' => 'Apify is collecting public posts. This stage can take the longest.',
            'import_posts' => 'Posts are back. SocialCore is preparing them for profile extraction.',
            'extract_urls' => 'SocialCore is selecting unique creator profiles from the discovered posts.',
            'enrichment_scrape' => 'Apify is enriching selected profiles. You will see previews as soon as they are safe to show.',
            'import_profiles' => 'SocialCore is scoring fit and preparing the review queue.',
            default => 'Discovery is running.',
        };
    }

    private function startPipeline(Request $request, array $payload, array $extraResponse = [])
    {
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $payload['workspaceId'] = $workspaceId;
        $payload['planId'] = $this->billing->currentPlanId($workspaceId);

        $preflight = $this->creditPreflight($workspaceId, $payload);
        if (!($preflight['billing']['canRun'] ?? false)) {
            $message = 'Not enough scrape credits available for this discovery run.';

            return response()->json(array_merge([
                'error' => $message,
                'code' => 'insufficient_credits',
                'message' => $message,
                'estimate' => $preflight['estimate'],
                'billing' => $preflight['billing'],
            ], $extraResponse), 402);
        }

        if ($request->boolean('wait')) {
            $state = $this->pipeline->createJob($payload);
            try {
                $result = $this->pipeline->runJob($state['jobId'], $payload);
                return response()->json(array_merge($result, $extraResponse));
            } catch (\Throwable $e) {
                return response()->json(array_merge([
                    'message' => 'Pipeline failed',
                    'status' => 'failed',
                    'jobId' => $state['jobId'],
                    'failedStep' => $this->pipeline->getJobState($state['jobId'])['failedStep'] ?? null,
                    'error' => config('app.debug') ? $e->getMessage() : 'Pipeline failed. Please retry or contact support.',
                ], $extraResponse), 500);
            }
        }

        $state = $this->pipeline->createJob($payload);

        RunPipelineJob::dispatch($state['jobId'], $payload);

        return response()->json(array_merge([
            'jobId' => $state['jobId'],
            'status' => 'running',
            'currentStep' => 'discovery_scrape',
        ], $extraResponse), 202);
    }

    private function creditPreflight(string $workspaceId, array $payload): array
    {
        $planId = (string) ($payload['planId'] ?? $this->billing->currentPlanId($workspaceId));
        $summary = $this->billing->summary($workspaceId);
        $hashtagCount = count((array) ($payload['hashtags'] ?? []));
        $seedCount = max(1, (int) ($payload['seedCount'] ?? ($hashtagCount ?: 1)));

        $estimate = $this->pipeline->estimate(
            $planId,
            (string) ($payload['platform'] ?? 'instagram'),
            (int) ($payload['discoveryLimit'] ?? 200),
            (int) ($payload['enrichmentLimit'] ?? 50),
            $seedCount,
            $payload['discoveryModuleKey'] ?? null,
            $payload['enrichmentModuleKey'] ?? null,
        );

        $required = max(0, (int) ($estimate['totals']['scrapeCredits'] ?? 0));
        $available = max(0, (int) ($summary['wallet']['totalScrapeCreditsAvailable'] ?? 0));
        $shortfall = max(0, $required - $available);

        return [
            'planId' => $planId,
            'estimate' => $estimate,
            'billing' => [
                'availableScrapeCredits' => $available,
                'requiredScrapeCredits' => $required,
                'remainingScrapeCreditsAfterRun' => max(0, $available - $required),
                'shortfallScrapeCredits' => $shortfall,
                'canRun' => $shortfall === 0,
                'requiresTopup' => $shortfall > 0,
            ],
        ];
    }

    private function resolveSheetId(Request $request, ?string $sheetId): string
    {
        return $this->workspaceContext->resolveWorkbookId($request, $sheetId);
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

    private function pilotPlatforms(): array
    {
        return config('outreach.launch.enable_tiktok', true)
            ? ['instagram', 'tiktok']
            : ['instagram'];
    }
}
