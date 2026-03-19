<?php

use App\Http\Controllers\ApifyController;
use App\Http\Controllers\SheetDataController;
use App\Http\Controllers\PipelineController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'loveframes-outreach-api',
    ]);
});

Route::middleware('app.key')->group(function () {
    Route::get('/auth-check', function () {
        return response()->json(['ok' => true]);
    });

    Route::post('/apify/run', [ApifyController::class, 'runActor']);
    Route::get('/apify/status/{runId}', [ApifyController::class, 'getRunStatus']);
    Route::get('/apify/results/{datasetId}', [ApifyController::class, 'getDatasetResults']);
    Route::post('/apify/import-results', [ApifyController::class, 'importDatasetToSheet']);

    Route::post('/crm/merge-enriched', [ApifyController::class, 'mergeEnrichedToCreators']);
    Route::post('/crm/merge-selected', [SheetDataController::class, 'mergeSelectedQueueToCrm']);
    Route::get('/crm/list', [SheetDataController::class, 'crmList']);
    Route::put('/crm/{id}', [SheetDataController::class, 'updateCreator']);

    Route::get('/discovery/list', [SheetDataController::class, 'discoveryList']);
    Route::post('/discovery/extract-urls', [SheetDataController::class, 'extractUrls']);
    Route::post('/discovery/push-to-enrichment', [SheetDataController::class, 'pushToEnrichment']);

    Route::get('/messages/list', [SheetDataController::class, 'messagesList']);
    Route::post('/messages/create', [SheetDataController::class, 'createMessage']);
    Route::put('/messages/{id}', [SheetDataController::class, 'updateMessage']);
    Route::delete('/messages/{id}', [SheetDataController::class, 'deleteMessage']);

    Route::get('/enrichment/queue', [SheetDataController::class, 'enrichmentQueue']);
    Route::get('/dashboard/metrics', [SheetDataController::class, 'dashboardMetrics']);
    Route::get('/operator/view', [SheetDataController::class, 'operatorView']);
    Route::get('/creators/{id}/decision-sheet', [SheetDataController::class, 'creatorDecisionSheet']);
    Route::post('/creators/{id}/transition', [SheetDataController::class, 'transitionCreator']);

    Route::post('/tasks/generate', [ApifyController::class, 'generateTasks']);
    Route::post('/tasks/{taskId}/complete', [ApifyController::class, 'completeTask']);
    Route::get('/tasks/list', [ApifyController::class, 'listTasks']);
    Route::post('/outreach/log', [ApifyController::class, 'logOutreachEvent']);

    Route::post('/pipeline/discover', [PipelineController::class, 'discover']);
    Route::get('/pipeline/status', [PipelineController::class, 'status']);
});
