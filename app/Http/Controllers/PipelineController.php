<?php

namespace App\Http\Controllers;

use App\Jobs\RunPipelineJob;
use App\Services\AiDiscoveryBriefService;
use App\Services\PipelineDiscoveryService;
use App\Services\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PipelineController extends Controller
{
    public function __construct(
        private PipelineDiscoveryService $pipeline,
        private WorkspaceContextService $workspaceContext,
        private AiDiscoveryBriefService $briefs,
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
            'rankingMetric' => ['nullable', 'string', Rule::in(['views', 'likes', 'comments', 'shares'])],
            'wait' => ['nullable', 'boolean'],
            'brief' => ['nullable', 'string'],
            'criteria' => ['nullable', 'array'],
        ]);

        $payload = [
            'sheetId' => $this->resolveSheetId($request, $validated['sheetId'] ?? null),
            'platform' => $validated['platform'],
            'hashtags' => $validated['hashtags'],
            'discoveryLimit' => (int) ($validated['discoveryLimit'] ?? 50),
            'enrichmentLimit' => (int) ($validated['enrichmentLimit'] ?? 20),
            'dedupeAgainstCRM' => (bool) ($validated['dedupeAgainstCRM'] ?? true),
            'rankingMetric' => (string) ($validated['rankingMetric'] ?? ''),
            'brief' => $validated['brief'] ?? null,
            'criteria' => $validated['criteria'] ?? null,
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
            'rankingMetric' => ['nullable', 'string', Rule::in(['views', 'likes', 'comments', 'shares'])],
            'wait' => ['nullable', 'boolean'],
        ]);

        $criteria = $this->briefs->parse((string) $validated['brief'], [
            'platform' => $validated['platform'],
            'projectContext' => $validated['projectContext'] ?? null,
        ]);

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
        ];

        return $this->startPipeline($request, $payload, [
            'criteria' => $criteria,
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

        return response()->json([
            'jobId' => $state['jobId'],
            'status' => $state['status'] ?? 'running',
            'currentStep' => $state['currentStep'] ?? null,
            'completedSteps' => $state['completedSteps'] ?? [],
            'creators' => ($state['status'] ?? '') === 'completed' ? ($state['creators'] ?? []) : [],
            'totalCreators' => ($state['status'] ?? '') === 'completed' ? ($state['totalCreators'] ?? 0) : 0,
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
                    'error' => $e->getMessage(),
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
}
