<?php

namespace App\Http\Controllers;

use App\Jobs\RunPipelineJob;
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
            'platform' => ['required', 'string', Rule::in(['instagram', 'tiktok'])],
            'hashtags' => ['required', 'array', 'min:1'],
            'hashtags.*' => ['string'],
            'discoveryLimit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'enrichmentLimit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'dedupeAgainstCRM' => ['nullable', 'boolean'],
            'rankingMetric' => ['nullable', 'string', Rule::in(['none', 'views_desc', 'views_asc', 'likes_desc', 'likes_asc', 'comments_desc', 'comments_asc', 'shares_desc', 'shares_asc'])],
            'wait' => ['nullable', 'boolean'],
            'brief' => ['nullable', 'string'],
            'criteria' => ['nullable', 'array'],
            'discoveryModuleKey' => ['nullable', 'string'],
            'enrichmentModuleKey' => ['nullable', 'string'],
        ]);

        $criteria = $this->normalizeDiscoveryCriteria((array) ($validated['criteria'] ?? []));

        $payload = [
            'sheetId' => $this->resolveSheetId($request, $validated['sheetId'] ?? null),
            'platform' => $validated['platform'],
            'hashtags' => $validated['hashtags'],
            'discoveryLimit' => (int) ($validated['discoveryLimit'] ?? 50),
            'enrichmentLimit' => (int) ($validated['enrichmentLimit'] ?? 20),
            'dedupeAgainstCRM' => (bool) ($validated['dedupeAgainstCRM'] ?? true),
            'rankingMetric' => (string) ($validated['rankingMetric'] ?? ''),
            'brief' => $validated['brief'] ?? null,
            'criteria' => $criteria !== [] ? $criteria : null,
            'discoveryModuleKey' => $validated['discoveryModuleKey'] ?? null,
            'enrichmentModuleKey' => $validated['enrichmentModuleKey'] ?? null,
        ];

        return $this->startPipeline($request, $payload);
    }

    public function discoverFromBrief(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'platform' => ['required', 'string', Rule::in(['instagram', 'tiktok'])],
            'brief' => ['required', 'string', 'min:8', 'max:5000'],
            'projectContext' => ['nullable', 'string'],
            'discoveryLimit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'enrichmentLimit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'dedupeAgainstCRM' => ['nullable', 'boolean'],
            'rankingMetric' => ['nullable', 'string', Rule::in(['none', 'views_desc', 'views_asc', 'likes_desc', 'likes_asc', 'comments_desc', 'comments_asc', 'shares_desc', 'shares_asc'])],
            'wait' => ['nullable', 'boolean'],
            'discoveryModuleKey' => ['nullable', 'string'],
            'enrichmentModuleKey' => ['nullable', 'string'],
        ]);

        $criteria = $this->normalizeDiscoveryCriteria($this->briefs->parse((string) $validated['brief'], [
            'platform' => $validated['platform'],
            'projectContext' => $validated['projectContext'] ?? null,
        ]));

        $payload = [
            'sheetId' => $this->resolveSheetId($request, $validated['sheetId'] ?? null),
            'platform' => $validated['platform'],
            'hashtags' => $criteria['hashtags'] ?? [],
            'discoveryLimit' => (int) ($validated['discoveryLimit'] ?? ($criteria['recommendedDiscoveryLimit'] ?? 60)),
            'enrichmentLimit' => (int) ($validated['enrichmentLimit'] ?? ($criteria['recommendedEnrichmentLimit'] ?? 25)),
            'dedupeAgainstCRM' => (bool) ($validated['dedupeAgainstCRM'] ?? true),
            'rankingMetric' => (string) ($validated['rankingMetric'] ?? ''),
            'brief' => (string) $validated['brief'],
            'criteria' => $criteria,
            'discoveryModuleKey' => $validated['discoveryModuleKey'] ?? null,
            'enrichmentModuleKey' => $validated['enrichmentModuleKey'] ?? null,
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
            'platform' => ['required', 'string', Rule::in(['instagram', 'tiktok'])],
            'discoveryLimit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'enrichmentLimit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'hashtags' => ['nullable', 'array'],
            'seedCount' => ['nullable', 'integer', 'min:1', 'max:50'],
            'discoveryModuleKey' => ['nullable', 'string'],
            'enrichmentModuleKey' => ['nullable', 'string'],
        ]);

        $workspaceId = (string) $request->attributes->get('workspace_id');
        $planId = $this->billing->currentPlanId($workspaceId);
        $summary = $this->billing->summary($workspaceId);
        $seedCount = max(1, count((array) ($validated['hashtags'] ?? [])) ?: (int) ($validated['seedCount'] ?? 1));

        $estimate = $this->pipeline->estimate(
            $planId,
            (string) $validated['platform'],
            (int) ($validated['discoveryLimit'] ?? 50),
            (int) ($validated['enrichmentLimit'] ?? 20),
            $seedCount,
            $validated['discoveryModuleKey'] ?? null,
            $validated['enrichmentModuleKey'] ?? null,
        );

        $available = (int) ($summary['wallet']['totalScrapeCreditsAvailable'] ?? 0);

        return response()->json([
            'message' => 'Pipeline estimate calculated',
            'data' => [
                'planId' => $planId,
                'estimate' => $estimate,
                'billing' => [
                    'availableScrapeCredits' => $available,
                    'remainingScrapeCreditsAfterRun' => max(0, $available - (int) ($estimate['totals']['scrapeCredits'] ?? 0)),
                ],
            ],
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

        return response()->json([
            'jobId' => $state['jobId'],
            'status' => $state['status'] ?? 'running',
            'currentStep' => $state['currentStep'] ?? null,
            'completedSteps' => $state['completedSteps'] ?? [],
            'creators' => $state['creators'] ?? [],
            'totalCreators' => $state['totalCreators'] ?? 0,
            'failedStep' => $state['failedStep'] ?? null,
            'steps' => $state['steps'] ?? [],
            'error' => $state['error'] ?? null,
            'criteria' => $state['criteria'] ?? null,
            'filterSummary' => $state['filterSummary'] ?? null,
            'brief' => $state['brief'] ?? null,
        ]);
    }

    private function startPipeline(Request $request, array $payload, array $extraResponse = [])
    {
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $payload['workspaceId'] = $workspaceId;
        $payload['planId'] = $this->billing->currentPlanId($workspaceId);
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
}

