<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCreditsException;
use App\Services\ApifyRowMapper;
use App\Services\CreatorMergeService;
use App\Services\OutreachLogService;
use App\Services\OperationalMirrorService;
use App\Services\TaskQueueService;
use App\Services\ScraperRegistryService;
use App\Services\ProviderUsageLogger;
use App\Services\WorkspaceBillingService;
use App\Services\WorkspaceContextService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use RuntimeException;

class ApifyController extends Controller
{
    public function __construct(
        private ApifyRowMapper $rowMapper,
        private CreatorMergeService $creatorMerge,
        private TaskQueueService $taskQueue,
        private OutreachLogService $outreachLog,
        private OperationalMirrorService $mirror,
        private WorkspaceContextService $workspaceContext,
        private ProviderUsageLogger $usageLogger,
        private WorkspaceBillingService $billing,
        private ScraperRegistryService $scrapers,
    ) {
    }

public function runActor(Request $request)
{
    $usageReservationId = null;

    try {
        $token = (string) config('services.apify.token');

        if ($token === '') {
            Log::error('APIFY_API_TOKEN missing');
            return response()->json(['error' => 'Missing APIFY_API_TOKEN'], 500);
        }

        $configuredActorKeys = array_keys($this->actorMap());
        $validated = $request->validate([
            'moduleKey' => ['nullable', 'string', 'max:120'],
            'actorKey' => ['nullable', 'string', 'max:120', Rule::in($configuredActorKeys)],
            'actorId' => ['nullable', 'string', 'max:255'],
            'memoryMbytes' => ['nullable', 'integer', 'min:128', 'max:4096'],
            'timeoutSecs' => ['nullable', 'integer', 'min:1', 'max:600'],
            'input' => ['nullable', 'array', 'max:100'],
        ]);

        $workspaceId = (string) $request->attributes->get('workspace_id');
        $planId = $this->billing->currentPlanId($workspaceId);
        $moduleKey = $validated['moduleKey'] ?? null;
        $actorKey = $validated['actorKey'] ?? null;
        $requestedActorId = $validated['actorId'] ?? null;
        $module = $this->scrapers->resolveExecution($moduleKey, $actorKey, $requestedActorId, $planId);
        $moduleKey = $module['key'];
        $actorKey = $module['actorKey'];
        $actorId = $module['actorId'];

        $input = $validated['input'] ?? Arr::except($request->all(), [
            'moduleKey', 'actorKey', 'actorId', 'maxTotalChargeUsd', 'memoryMbytes', 'timeoutSecs'
        ]);
        $this->scrapers->assertWithinExecutionLimit($module, $input);
        $maxTotalChargeUsd = $this->scrapers->providerChargeLimitUsd($moduleKey, $actorKey, $actorId, $input);

        $query = array_filter([
            'maxTotalChargeUsd' => $maxTotalChargeUsd,
            'memoryMbytes' => $validated['memoryMbytes'] ?? null,
            'timeoutSecs' => $validated['timeoutSecs'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $usageReservation = $this->billing->reserveApify(
            workspaceId: $workspaceId,
            moduleKey: $moduleKey,
            actorKey: $actorKey,
            actorId: $actorId,
            input: $input,
            maxChargeUsd: $maxTotalChargeUsd,
        );
        $usageReservationId = $usageReservation['usage_event_id'] ?? null;

        $url = "https://api.apify.com/v2/acts/{$actorId}/runs";
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        Log::info('Starting Apify actor run', [
            'moduleKey' => $moduleKey,
            'planId' => $planId,
            'actorKey' => $actorKey,
            'actorId' => $actorId,
            'url' => $url,
            'inputSummary' => $this->payloadSummary($input),
            'usageReservation' => array_intersect_key($usageReservation, array_flip(['credit_bucket', 'credit_cost', 'remaining_balance'])),
        ]);

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(90)
            ->post($url, $input);

        if (!$response->successful()) {
            Log::error('Apify run failed', [
                'moduleKey' => $moduleKey,
                'actorKey' => $actorKey,
                'actorId' => $actorId,
                'status' => $response->status(),
                'inputSummary' => $this->payloadSummary($input),
            ]);

            if ($usageReservationId) {
                $this->billing->refundReservation($usageReservationId, 'Apify run failed to start', [
                    'status' => $response->status(),
                ]);
            }

            $this->usageLogger->logApify([
                'actor_id' => $actorId,
                'actor_key' => $actorKey,
                'status' => 'failed_to_start',
                'max_total_charge_usd' => $maxTotalChargeUsd,
                'request_payload' => $input,
                'response_payload' => $response->json() ?? ['body' => $response->body()],
                'error_message' => 'Apify run failed',
            ]);

            return response()->json([
                'error' => 'Apify run failed',
                'apifyStatus' => $response->status(),
            ], $response->status());
        }

        $responsePayload = $response->json();
        $runId = data_get($responsePayload, 'data.id');
        $datasetId = data_get($responsePayload, 'data.defaultDatasetId');

        if ($usageReservationId) {
            $this->billing->consumeReservation(
                $usageReservationId,
                // This route only starts an async Apify run, so the real provider cost is not known yet.
                // Do not store maxTotalChargeUsd as actual spend; it is only a safety cap.
                providerCostUsd: null,
                metadata: [
                    'module_key' => $moduleKey,
                    'run_id' => $runId,
                    'dataset_id' => $datasetId,
                    'provider_cost_source' => 'apify_async_run_pending',
                    'max_total_charge_usd' => isset($query['maxTotalChargeUsd']) ? (float) $query['maxTotalChargeUsd'] : null,
                ],
                referenceId: $runId,
            );
        }

        $this->usageLogger->logApify([
            'actor_id' => $actorId,
            'actor_key' => $actorKey,
            'run_id' => $runId,
            'dataset_id' => $datasetId,
            'status' => data_get($responsePayload, 'data.status'),
            'max_total_charge_usd' => $maxTotalChargeUsd,
            'request_payload' => $input,
            'response_payload' => $responsePayload,
        ]);

        return response()->json([
            'message' => 'Actor started',
            'module' => [
                'key' => $module['key'],
                'label' => $module['label'],
                'platform' => $module['platform'],
                'stage' => $module['stage'],
                'depth' => $module['depth'],
                'targetSheet' => $module['targetSheet'],
            ],
            'actorId' => $actorId,
            'billing' => [
                'creditBucket' => $usageReservation['credit_bucket'] ?? 'scrape',
                'creditCost' => $usageReservation['credit_cost'] ?? null,
                'remainingBalance' => $usageReservation['remaining_balance'] ?? null,
            ],
            'apify' => $responsePayload,
        ]);
    } catch (InsufficientCreditsException $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'billing' => $e->context(),
        ], 402);
    } catch (\Throwable $e) {
        if ($usageReservationId) {
            $this->billing->refundReservation($usageReservationId, 'Unhandled Apify exception', [
                'message' => $e->getMessage(),
            ]);
        }

        Log::error('Unhandled exception in runActor', [
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'error' => 'Unhandled backend error while starting actor',
            'message' => config('app.debug') ? $e->getMessage() : 'The scraper could not be started. Please retry.',
        ], 500);
    }
}

public function modules(Request $request)
{
    $workspaceId = (string) $request->attributes->get('workspace_id');
    $planId = $this->billing->currentPlanId($workspaceId);

    return response()->json([
        'message' => 'Scraper modules fetched',
        'data' => [
            'planId' => $planId,
            'modules' => $this->scrapers->availableForPlan(
                $planId,
                platform: $request->query('platform') ?: null,
                stage: $request->query('stage') ?: null,
                configuredOnly: true,
            ),
        ],
    ]);
}

    public function getRunStatus(Request $request, string $runId)
    {
        $this->assertApifyReferenceBelongsToWorkspace($request, 'run_id', $runId);

        $token = (string) config('services.apify.token');

        if ($token === '') {
            return response()->json(['error' => 'Missing APIFY_API_TOKEN'], 500);
        }

        $response = Http::withToken($token)
            ->get("https://api.apify.com/v2/actor-runs/{$runId}");

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Failed to fetch run status',
                'status' => $response->status(),
                'body' => config('app.debug') ? ($response->json() ?? $response->body()) : null,
            ], 500);
        }

        return response()->json([
            'message' => 'Run status fetched',
            'apify' => $response->json(),
        ]);
    }

public function getDatasetResults(Request $request, string $datasetId)
{
    try {
        $this->assertApifyReferenceBelongsToWorkspace($request, 'dataset_id', $datasetId);

        $token = (string) config('services.apify.token');

        if ($token === '') {
            return response()->json([
                'error' => 'Missing APIFY_API_TOKEN',
            ], 500);
        }

        $limit = min(max((int) $request->query('limit', 100), 1), 500);
        $offset = max((int) $request->query('offset', 0), 0);

        $response = Http::withToken($token)
            ->acceptJson()
            ->get("https://api.apify.com/v2/datasets/{$datasetId}/items", [
                'clean' => 'true',
                'format' => 'json',
                'limit' => $limit,
                'offset' => $offset,
            ]);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Failed to fetch dataset results',
                'status' => $response->status(),
            ], $response->status());
        }

        $items = json_decode($response->body(), true);

        if (!is_array($items)) {
            return response()->json([
                'error' => 'Dataset response was not a valid JSON array',
                'message' => config('app.debug') ? $response->body() : 'Invalid dataset response from provider.',
            ], 500);
        }

        return response()->json([
            'message' => 'Dataset results fetched',
            'datasetId' => $datasetId,
            'count' => count($items),
            'items' => $items,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'error' => 'Unhandled error while fetching dataset results',
            'message' => config('app.debug') ? $e->getMessage() : 'Dataset results could not be fetched.',
        ], 500);
    }
}

    public function importDatasetToSheet(Request $request)
    {
        $validated = $request->validate([
            'datasetId' => ['required', 'string'],
            'sheetName' => ['required', 'string', Rule::in(ApifyRowMapper::IMPORTABLE_SHEETS)],
            'sheetId' => ['nullable', 'string'],
            'platform' => ['nullable', 'string', Rule::in(['instagram', 'tiktok'])],
            'sourceNotes' => ['nullable', 'string'],
        ]);

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $this->assertApifyReferenceBelongsToWorkspace($request, 'dataset_id', $validated['datasetId']);
        $items = $this->fetchDatasetItems($validated['datasetId']);

        if (count($items) === 0) {
            return response()->json([
                'message' => 'No items found in dataset',
                'datasetId' => $validated['datasetId'],
                'sheetName' => $validated['sheetName'],
            ]);
        }

        $rows = $this->rowMapper->mapRowsForSheet($validated['sheetName'], $items, [
            'platform' => $validated['platform'] ?? null,
            'sourceNotes' => $validated['sourceNotes'] ?? null,
        ]);

        if (count($rows) === 0) {
            return response()->json([
                'message' => 'No mappable rows found for target sheet',
                'datasetId' => $validated['datasetId'],
                'sheetName' => $validated['sheetName'],
            ], 422);
        }

        return response()->json([
            'message' => 'Dataset rows mapped; external spreadsheet writes are disabled',
            'datasetId' => $validated['datasetId'],
            'sheetId' => $sheetId,
            'sheetName' => $validated['sheetName'],
            'mappedRows' => count($rows),
        ]);
    }

public function mergeEnrichedToCreators(Request $request)
{
    try {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'sourceSheet' => ['required', 'string', Rule::in(CreatorMergeService::SOURCE_SHEETS)],
            'createTasks' => ['nullable', 'boolean'],
            'taskLimit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $result = $this->creatorMerge->mergeFromEnrichedSheet($sheetId, $validated['sourceSheet']);

        $affectedRowNumbers = array_values(array_unique(array_filter(
            array_map('intval', (array) ($result['affectedRowNumbers'] ?? [])),
            fn (int $rowNumber) => $rowNumber > 1
        )));
        $affectedProfileIds = array_values(array_unique(array_filter(
            array_map('strval', (array) ($result['affectedProfileIds'] ?? [])),
            fn (string $profileId) => trim($profileId) !== ''
        )));

        if ($affectedProfileIds === [] && $affectedRowNumbers !== []) {
            $this->mirror->syncCreators($sheetId, $affectedRowNumbers);
        }

        if (($validated['createTasks'] ?? false) === true) {
            if ($affectedProfileIds === [] && $affectedRowNumbers === []) {
                $result['taskGeneration'] = [
                    'created' => 0,
                    'taskSheet' => 'Task_Queue',
                    'sourceRowNumbers' => [],
                    'sourceProfileIds' => [],
                ];
            } else {
                try {
                    $taskOptions = [
                        'limit' => $validated['taskLimit'] ?? 50,
                    ];
                    if ($affectedProfileIds !== []) {
                        $taskOptions['profileIds'] = $affectedProfileIds;
                    } else {
                        $taskOptions['rowNumbers'] = $affectedRowNumbers;
                    }
                    $result['taskGeneration'] = $this->taskQueue->generateInitialTasks($sheetId, $taskOptions);
                } catch (\Throwable $e) {
                    report($e);
                    $result['taskGenerationError'] = $e->getMessage();
                }
            }
        }

        return response()->json([
            'message' => 'Enriched profiles merged into Creators_CRM',
            'sheetId' => $sheetId,
            'result' => $result,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'error' => 'Merge failed',
            'message' => config('app.debug') ? $e->getMessage() : 'Merge failed. Please retry or contact support.',
        ], 500);
    }
}

    public function taskSettings(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
        ]);

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $data = $this->taskQueue->getTaskSettings($sheetId);

        return response()->json([
            'message' => 'Task settings fetched',
            'sheetId' => $sheetId,
            ...$data,
        ]);
    }

    public function updateTaskSettings(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'settings' => ['required', 'array'],
        ]);

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $data = $this->taskQueue->updateTaskSettings($sheetId, $validated['settings']);

        return response()->json([
            'message' => 'Task settings updated',
            'sheetId' => $sheetId,
            ...$data,
        ]);
    }

    public function generateTasks(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $result = $this->taskQueue->generateInitialTasks($sheetId, [
            'limit' => $validated['limit'] ?? 50,
        ]);

        return response()->json([
            'message' => 'Tasks generated',
            'sheetId' => $sheetId,
            'result' => $result,
        ]);
    }

    public function createTask(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'platform' => ['required', 'string', Rule::in(['instagram', 'tiktok', 'email'])],
            'handle' => ['required', 'string', 'max:255'],
            'taskType' => ['required', 'string', Rule::in(['FOLLOW_REQUEST', 'DM_INVITE', 'DM_FOLLOWUP', 'EMAIL_SEND', 'REVIEW_CREATOR', 'COMMENT_ON_POST', 'NEGOTIATE_TERMS', 'CHECK_IN', 'CONFIRM_POSTED', 'CONFIRM_ACCEPTED', 'ARCHIVE_CREATOR'])],
            'priority' => ['nullable', 'string', Rule::in(['LOW', 'MEDIUM', 'HIGH', 'URGENT', 'low', 'medium', 'high', 'urgent'])],
            'profileUrl' => ['nullable', 'string'],
            'messageText' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'dueAt' => ['nullable', 'string'],
        ]);

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $task = $this->taskQueue->createManualTask($sheetId, $validated);

        return response()->json([
            'message' => 'Task created',
            'sheetId' => $sheetId,
            'task' => $task,
        ]);
    }

    public function resolveOutreachTask(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'platform' => ['nullable', 'string', Rule::in(['instagram', 'tiktok', 'email'])],
            'handle' => ['required', 'string', 'max:255'],
            'taskType' => ['nullable', 'string', Rule::in(['FOLLOW_REQUEST', 'DM_INVITE', 'DM_FOLLOWUP', 'EMAIL_SEND', 'REVIEW_CREATOR', 'COMMENT_ON_POST', 'NEGOTIATE_TERMS', 'CHECK_IN', 'CONFIRM_POSTED', 'CONFIRM_ACCEPTED', 'ARCHIVE_CREATOR'])],
            'priority' => ['nullable', 'string', Rule::in(['LOW', 'MEDIUM', 'HIGH', 'URGENT', 'low', 'medium', 'high', 'urgent'])],
            'profileUrl' => ['nullable', 'string'],
            'messageText' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'dueAt' => ['nullable', 'string'],
            'creatorProfileId' => ['nullable', 'string'],
            'allowCreate' => ['nullable', 'boolean'],
        ]);

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $result = $this->taskQueue->resolveOrCreateOutreachTask($sheetId, $validated);

        return response()->json([
            'message' => $result['created'] ? 'Outreach task created' : 'Outreach task resolved',
            'sheetId' => $sheetId,
            'task' => $result['task'],
            'created' => $result['created'],
            'source' => $result['source'] ?? null,
        ]);
    }

    public function completeTask(Request $request, string $taskId)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'outcome' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'sender_account' => ['nullable', 'string'],
            'template_id' => ['nullable', 'string'],
            'message_draft' => ['nullable', 'string'],
            'skipReason' => ['nullable', 'string'],
            'skipReasonDetail' => ['nullable', 'string'],
            'externalChannel' => ['nullable', 'string'],
            'responseChannel' => ['nullable', 'string'],
            'conversationUrl' => ['nullable', 'string'],
            'markReplied' => ['nullable', 'boolean'],
            'actionType' => ['nullable', 'string', Rule::in(['FOLLOW_REQUEST', 'DM_INVITE', 'DM_FOLLOWUP', 'EMAIL_SEND', 'REVIEW_CREATOR', 'COMMENT_ON_POST', 'NEGOTIATE_TERMS', 'CHECK_IN', 'CONFIRM_POSTED', 'CONFIRM_ACCEPTED', 'ARCHIVE_CREATOR'])],
            'replacementTaskType' => ['nullable', 'string', Rule::in(['FOLLOW_REQUEST', 'DM_INVITE', 'DM_FOLLOWUP', 'EMAIL_SEND', 'REVIEW_CREATOR', 'COMMENT_ON_POST', 'NEGOTIATE_TERMS', 'CHECK_IN', 'CONFIRM_POSTED', 'CONFIRM_ACCEPTED', 'ARCHIVE_CREATOR'])],
            'openReplacement' => ['nullable', 'boolean'],
            'keepOriginalAsLaterTask' => ['nullable', 'boolean'],
            'completedRelatedTaskIds' => ['nullable', 'array'],
            'completedRelatedTaskIds.*' => ['string'],
            'keepRelatedTasksAsFollowup' => ['nullable', 'boolean'],
            'completedAdHocActions' => ['nullable', 'array'],
            'completedAdHocActions.*' => ['string', Rule::in(['FOLLOW_REQUEST', 'COMMENT_ON_POST'])],
        ]);

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $result = $this->taskQueue->completeTask($sheetId, $taskId, $validated);

        return response()->json([
            'message' => 'Task completed and logged',
            'sheetId' => $sheetId,
            'result' => $result,
        ]);
    }

    public function snoozeTask(Request $request, string $taskId)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'until' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
        ]);

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $until = Carbon::parse((string) $validated['until']);
        $result = $this->taskQueue->snoozeTask($sheetId, $taskId, $until, $validated['reason'] ?? null);

        return response()->json([
            'message' => 'Task snoozed',
            'sheetId' => $sheetId,
            'result' => $result,
        ]);
    }

    public function logOutreachEvent(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'Platform' => ['required', 'string'],
            'Handle' => ['required', 'string'],
            'Channel' => ['nullable', 'string'],
            'Event_Type' => ['required', 'string'],
            'Template_ID' => ['nullable', 'string'],
            'Sender_Account' => ['nullable', 'string'],
            'Sent_At' => ['nullable', 'string'],
            'Status' => ['nullable', 'string'],
            'URL' => ['nullable', 'string'],
            'Notes' => ['nullable', 'string'],
            'Message_Text' => ['nullable', 'string'],
        ]);

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $eventId = $this->outreachLog->appendEvent($sheetId, $validated);

        return response()->json([
            'message' => 'Outreach event logged',
            'sheetId' => $sheetId,
            'eventId' => $eventId,
        ]);
    }

    private function fetchDatasetItems(string $datasetId): array
    {
        $token = (string) config('services.apify.token');

        if ($token === '') {
            throw new RuntimeException('Missing APIFY_API_TOKEN');
        }

        $response = Http::withToken($token)
            ->get("https://api.apify.com/v2/datasets/{$datasetId}/items", [
                'clean' => 'true',
                'format' => 'json',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Failed to fetch dataset results: ' . $response->body());
        }

        $items = $response->json();

        return is_array($items) ? $items : [];
    }


    public function listTasks(Request $request)
{
    $validated = $request->validate([
        'sheetId' => ['nullable', 'string'],
    ]);

    $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
    $queueHealth = $this->taskQueue->queueHealth($sheetId);
    $tasks = $this->taskQueue->listTasks($sheetId);

    return response()->json([
        'message' => 'Tasks fetched',
        'sheetId' => $sheetId,
        'tasks' => $tasks,
        'queueHealth' => $queueHealth,
    ]);
}

        public function coldRetryTasks(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
        ]);

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $tasks = $this->taskQueue->listColdRetry($sheetId);

        return response()->json([
            'message' => 'Cold retry tasks fetched',
            'sheetId' => $sheetId,
            'tasks' => $tasks,
        ]);
    }
    
    private function assertApifyReferenceBelongsToWorkspace(Request $request, string $column, string $referenceId): void
    {
        if (!in_array($column, ['run_id', 'dataset_id'], true)) {
            return;
        }

        $workspaceId = trim((string) $request->attributes->get('workspace_id'));
        $referenceId = trim($referenceId);

        if ($workspaceId === '' || $referenceId === '' || !Schema::hasTable('apify_usage_logs')) {
            return;
        }

        $workspaceIds = DB::table('apify_usage_logs')
            ->where($column, $referenceId)
            ->whereNotNull('workspace_id')
            ->pluck('workspace_id')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($workspaceIds === []) {
            return;
        }

        if (!in_array($workspaceId, $workspaceIds, true)) {
            abort(403, 'Requested Apify resource does not belong to the active workspace.');
        }
    }
    
    private function payloadSummary(array $payload): array
    {
        return [
            'keys' => array_keys($payload),
            'bytes' => strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        ];
    }

    private function actorMap(): array
    {
        return $this->scrapers->configuredActorMap();
    }
}
