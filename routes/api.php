<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\ApifyController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\SheetDataController;
use App\Http\Controllers\PipelineController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'social-core-api',
    ]);
});

Route::get('/avatar-proxy', [SheetDataController::class, 'avatarProxy'])->middleware('throttle:avatar');
Route::post('/billing/webhooks/stripe', [BillingController::class, 'stripeWebhook'])->middleware('throttle:60,1');

Route::middleware(['api.auth', 'workspace.context', 'throttle:api'])->group(function () {
    Route::get('/auth-check', function () {
        return response()->json([
            'ok' => true,
            'workspaceId' => request()->attributes->get('workspace_id'),
            'workspaceRole' => request()->attributes->get('workspace_role'),
            'legacyAccess' => (bool) request()->attributes->get('legacy_api_access'),
        ]);
    });

    Route::get('/apify/modules', [ApifyController::class, 'modules']);
    Route::middleware('throttle:expensive')->group(function () {
        Route::post('/apify/run', [ApifyController::class, 'runActor']);
        Route::post('/apify/import-results', [ApifyController::class, 'importDatasetToSheet']);
    });
    Route::get('/apify/status/{runId}', [ApifyController::class, 'getRunStatus']);
    Route::get('/apify/results/{datasetId}', [ApifyController::class, 'getDatasetResults'])->middleware('throttle:expensive');

    Route::post('/crm/merge-enriched', [ApifyController::class, 'mergeEnrichedToCreators'])->middleware('throttle:expensive');
    Route::post('/crm/merge-selected', [SheetDataController::class, 'mergeSelectedQueueToCrm'])->middleware('throttle:expensive');
    Route::get('/crm/list', [SheetDataController::class, 'crmList']);
    Route::put('/crm/{id}', [SheetDataController::class, 'updateCreator']);
    Route::delete('/crm/{id}', [SheetDataController::class, 'deleteCreator'])->middleware('workspace.role:owner,admin');
    Route::post('/crm/link-profiles', [SheetDataController::class, 'linkProfiles']);

    Route::post('/outreach/log', [ApifyController::class, 'logOutreachEvent']);

    Route::get('/discovery/list', [SheetDataController::class, 'discoveryList']);
    Route::post('/discovery/extract-urls', [SheetDataController::class, 'extractUrls'])->middleware('throttle:expensive');
    Route::post('/discovery/push-to-enrichment', [SheetDataController::class, 'pushToEnrichment'])->middleware('throttle:expensive');

    Route::get('/messages/list', [SheetDataController::class, 'messagesList']);
    Route::post('/messages/create', [SheetDataController::class, 'createMessage']);
    Route::put('/messages/{id}', [SheetDataController::class, 'updateMessage']);
    Route::delete('/messages/{id}', [SheetDataController::class, 'deleteMessage'])->middleware('workspace.role:owner,admin');

    Route::get('/enrichment/queue', [SheetDataController::class, 'enrichmentQueue']);
    Route::get('/dashboard/metrics', [SheetDataController::class, 'dashboardMetrics']);
    Route::get('/operator/view', [SheetDataController::class, 'operatorView']);
    Route::get('/creators/{id}/decision-sheet', [SheetDataController::class, 'creatorDecisionSheet']);
    Route::post('/creators/{id}/transition', [SheetDataController::class, 'transitionCreator']);

    Route::get('/tasks/settings', [ApifyController::class, 'taskSettings']);
    Route::put('/tasks/settings', [ApifyController::class, 'updateTaskSettings'])->middleware('workspace.role:owner,admin');
    Route::post('/tasks/generate', [ApifyController::class, 'generateTasks'])->middleware('throttle:expensive');
    Route::post('/tasks/create', [ApifyController::class, 'createTask']);
    Route::get('/tasks/cold-retry', [ApifyController::class, 'coldRetryTasks']);
    Route::get('/tasks/list', [ApifyController::class, 'listTasks']);
    Route::post('/tasks/{taskId}/complete', [ApifyController::class, 'completeTask']);
    Route::post('/tasks/{taskId}/snooze', [ApifyController::class, 'snoozeTask']);

    Route::get('/billing/summary', [BillingController::class, 'summary']);
    Route::get('/billing/catalog', [BillingController::class, 'catalog']);
    Route::middleware('workspace.role:owner,admin')->group(function () {
        Route::post('/billing/checkout/subscription', [BillingController::class, 'checkoutSubscription'])->middleware('throttle:expensive');
        Route::post('/billing/checkout/topup', [BillingController::class, 'checkoutTopup'])->middleware('throttle:expensive');
    });

    Route::post('/pipeline/estimate', [PipelineController::class, 'estimate'])->middleware('throttle:expensive');
    Route::post('/pipeline/discover', [PipelineController::class, 'discover'])->middleware('throttle:expensive');
    Route::post('/pipeline/discover-from-brief', [PipelineController::class, 'discoverFromBrief'])->middleware('throttle:expensive');
    Route::get('/pipeline/status', [PipelineController::class, 'status']);

    Route::middleware('throttle:expensive')->group(function () {
        Route::post('/ai/parse-discovery-brief', [AiController::class, 'parseDiscoveryBrief']);
        Route::post('/ai/score-creators', [AiController::class, 'scoreCreators']);
        Route::post('/ai/personalize-message', [AiController::class, 'personalizeMessage']);
        Route::post('/ai/detect-duplicates', [AiController::class, 'detectDuplicates']);
    });
});
