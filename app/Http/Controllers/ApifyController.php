<?php

namespace App\Http\Controllers;

use App\Services\ApifyRowMapper;
use App\Services\CreatorMergeService;
use App\Services\GoogleSheetsService;
use App\Services\OutreachLogService;
use App\Services\OperationalMirrorService;
use App\Services\TaskQueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

class ApifyController extends Controller
{
    public function __construct(
        private ApifyRowMapper $rowMapper,
        private GoogleSheetsService $sheets,
        private CreatorMergeService $creatorMerge,
        private TaskQueueService $taskQueue,
        private OutreachLogService $outreachLog,
        private OperationalMirrorService $mirror,
    ) {
    }

public function runActor(Request $request)
{
    try {
        $token = (string) config('services.apify.token');

        if ($token === '') {
            Log::error('APIFY_API_TOKEN missing');
            return response()->json(['error' => 'Missing APIFY_API_TOKEN'], 500);
        }

        $validated = $request->validate([
            'actorKey' => ['nullable', 'string', Rule::in(array_keys($this->actorMap()))],
            'actorId' => ['nullable', 'string'],
            'maxTotalChargeUsd' => ['nullable', 'numeric', 'min:0'],
            'memoryMbytes' => ['nullable', 'integer', 'min:128'],
            'timeoutSecs' => ['nullable', 'integer', 'min:1'],
            'input' => ['nullable', 'array'],
        ]);

        $actorMap = $this->actorMap();
        $actorKey = $validated['actorKey'] ?? null;
        $actorId = $validated['actorId'] ?? $actorMap[$actorKey ?? ''] ?? null;

        if (!$actorId) {
            Log::warning('Missing actorId or unmapped actorKey', [
                'actorKey' => $actorKey,
                'actorMap' => $actorMap,
            ]);

            return response()->json([
                'error' => 'Missing actorId or unmapped actorKey',
            ], 422);
        }

        $input = $validated['input'] ?? Arr::except($request->all(), [
            'actorKey', 'actorId', 'maxTotalChargeUsd', 'memoryMbytes', 'timeoutSecs'
        ]);

        $query = array_filter([
            'maxTotalChargeUsd' => $validated['maxTotalChargeUsd'] ?? config('services.apify.default_max_total_charge_usd'),
            'memoryMbytes' => $validated['memoryMbytes'] ?? null,
            'timeoutSecs' => $validated['timeoutSecs'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $url = "https://api.apify.com/v2/acts/{$actorId}/runs";
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        Log::info('Starting Apify actor run', [
            'actorKey' => $actorKey,
            'actorId' => $actorId,
            'url' => $url,
            'input' => $input,
        ]);

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(90)
            ->post($url, $input);

        if (!$response->successful()) {
            Log::error('Apify run failed', [
                'actorKey' => $actorKey,
                'actorId' => $actorId,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'input' => $input,
            ]);

            return response()->json([
                'error' => 'Apify run failed',
                'apifyStatus' => $response->status(),
                'apifyBody' => $response->json() ?? $response->body(),
            ], $response->status());
        }

        Log::info('Apify actor run started', [
            'actorKey' => $actorKey,
            'actorId' => $actorId,
            'response' => $response->json(),
        ]);

        return response()->json([
            'message' => 'Actor started',
            'actorId' => $actorId,
            'apify' => $response->json(),
        ]);
    } catch (\Throwable $e) {
        Log::error('Unhandled exception in runActor', [
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'error' => 'Unhandled backend error while starting actor',
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ], 500);
    }
}

    public function getRunStatus(string $runId)
    {
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
                'body' => $response->json() ?? $response->body(),
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
        $token = (string) config('services.apify.token');

        if ($token === '') {
            return response()->json([
                'error' => 'Missing APIFY_API_TOKEN',
            ], 500);
        }

        $limit = (int) $request->query('limit', 100);
        $offset = (int) $request->query('offset', 0);

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
                'body' => $response->body(),
            ], $response->status());
        }

        $items = json_decode($response->body(), true);

        if (!is_array($items)) {
            return response()->json([
                'error' => 'Dataset response was not a valid JSON array',
                'raw_body' => $response->body(),
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
            'message' => $e->getMessage(),
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

        $sheetId = $validated['sheetId'] ?: (string) config('services.google.default_sheet_id');
        if ($sheetId === '') {
            return response()->json(['error' => 'Missing sheetId and GOOGLE_DEFAULT_SHEET_ID'], 500);
        }

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

        $this->sheets->appendRows($sheetId, $validated['sheetName'], $rows);

        return response()->json([
            'message' => 'Dataset imported to Google Sheet',
            'datasetId' => $validated['datasetId'],
            'sheetId' => $sheetId,
            'sheetName' => $validated['sheetName'],
            'importedRows' => count($rows),
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

        $sheetId = $validated['sheetId'] ?: (string) config('services.google.default_sheet_id');
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
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ], 500);
    }
}

    public function generateTasks(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $sheetId = $validated['sheetId'] ?: (string) config('services.google.default_sheet_id');
        $result = $this->taskQueue->generateInitialTasks($sheetId, [
            'limit' => $validated['limit'] ?? 50,
        ]);

        return response()->json([
            'message' => 'Tasks generated',
            'sheetId' => $sheetId,
            'result' => $result,
        ]);
    }

    public function completeTask(Request $request, string $taskId)
    {
$validated = $request->validate([
    'sheetId' => ['nullable', 'string'],
    'status' => ['nullable', 'string'],
    'notes' => ['nullable', 'string'],
    'sender_account' => ['nullable', 'string'],
    'template_id' => ['nullable', 'string'],
    'message_draft' => ['nullable', 'string'],
]);

        $sheetId = $validated['sheetId'] ?: (string) config('services.google.default_sheet_id');
        $result = $this->taskQueue->completeTask($sheetId, $taskId, $validated);

        return response()->json([
            'message' => 'Task completed and logged',
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
        ]);

        $sheetId = $validated['sheetId'] ?: (string) config('services.google.default_sheet_id');
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

    $sheetId = $validated['sheetId'] ?: (string) config('services.google.default_sheet_id');
    $tasks = $this->taskQueue->listTasks($sheetId);

    return response()->json([
        'message' => 'Tasks fetched',
        'sheetId' => $sheetId,
        'tasks' => $tasks,
    ]);
}
    
    private function actorMap(): array
    {
        return [
            'instagram_discovery' => (string) config('services.apify.actors.instagram_discovery'),
            'tiktok_discovery' => (string) config('services.apify.actors.tiktok_discovery'),
            'instagram_profile' => (string) config('services.apify.actors.instagram_profile'),
            'tiktok_profile' => (string) config('services.apify.actors.tiktok_profile'),
        ];
    }
}
