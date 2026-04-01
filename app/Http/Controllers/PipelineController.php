<?php

namespace App\Http\Controllers;

use App\Services\PipelineDiscoveryService;
use App\Services\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Jobs\RunPipelineJob;

class PipelineController extends Controller
{
    public function __construct(
        private PipelineDiscoveryService $pipeline,
        private WorkspaceContextService $workspaceContext,
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
            'wait' => ['nullable', 'boolean'],
        ]);

        $payload = [
            'sheetId' => $this->resolveSheetId($request, $validated['sheetId'] ?? null),
            'platform' => $validated['platform'],
            'hashtags' => $validated['hashtags'],
            'discoveryLimit' => (int) ($validated['discoveryLimit'] ?? 50),
            'enrichmentLimit' => (int) ($validated['enrichmentLimit'] ?? 20),
            'dedupeAgainstCRM' => (bool) ($validated['dedupeAgainstCRM'] ?? true),
        ];

        if ($request->boolean('wait')) {
            $state = $this->pipeline->createJob($payload);
            try {
                $result = $this->pipeline->runJob($state['jobId'], $payload);
                return response()->json($result);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'Pipeline failed',
                    'status' => 'failed',
                    'jobId' => $state['jobId'],
                    'failedStep' => $this->pipeline->getJobState($state['jobId'])['failedStep'] ?? null,
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        $state = $this->pipeline->createJob($payload);

        RunPipelineJob::dispatch($state['jobId'], $payload);

        return response()->json([
            'jobId' => $state['jobId'],
            'status' => 'running',
            'currentStep' => 'discovery_scrape',
        ], 202);
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
        ]);
    }

    private function resolveSheetId(Request $request, ?string $sheetId): string
    {
        return $this->workspaceContext->resolveWorkbookId($request, $sheetId);
    }
}
