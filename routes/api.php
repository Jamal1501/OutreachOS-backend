<?php

use App\Http\Controllers\ApifyController;
use App\Http\Controllers\SheetDataController;
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
    Route::post('/tasks/generate', [ApifyController::class, 'generateTasks']);
    Route::post('/tasks/{taskId}/complete', [ApifyController::class, 'completeTask']);
    Route::post('/outreach/log', [ApifyController::class, 'logOutreachEvent']);
    Route::get('/tasks/list', [ApifyController::class, 'listTasks']);

    Route::get('/discovery/list', [SheetDataController::class, 'discoveryList']);
    Route::post('/discovery/extract-urls', [SheetDataController::class, 'discoveryExtractUrls']);
    Route::post('/discovery/push-to-enrichment', [SheetDataController::class, 'discoveryPushToEnrichment']);

    Route::get('/crm/list', [SheetDataController::class, 'crmList']);

    Route::get('/messages/list', [SheetDataController::class, 'messagesList']);
    Route::post('/messages/create', [SheetDataController::class, 'messagesCreate']);
    Route::put('/messages/{id}', [SheetDataController::class, 'messagesUpdate']);
    Route::delete('/messages/{id}', [SheetDataController::class, 'messagesDelete']);

    Route::get('/enrichment/queue', [SheetDataController::class, 'enrichmentQueue']);
    Route::get('/dashboard/metrics', [SheetDataController::class, 'dashboardMetrics']);
});
