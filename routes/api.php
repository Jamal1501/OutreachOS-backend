<?php

use App\Http\Controllers\ApifyController;
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
});
