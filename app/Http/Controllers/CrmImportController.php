<?php

namespace App\Http\Controllers;

use App\Exceptions\CrmImportValidationException;
use App\Services\CrmFileImportService;
use App\Services\TaskQueueService;
use App\Services\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CrmImportController extends Controller
{
    public function __construct(
        private WorkspaceContextService $workspaceContext,
        private CrmFileImportService $importer,
        private TaskQueueService $taskQueue,
    ) {}

    public function previewCreators(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        try {
            $preview = $this->importer->previewCreatorsCsv($request->file('file'));
        } catch (CrmImportValidationException $exception) {
            return $this->invalidFileResponse($exception);
        }

        return response()->json([
            'message' => 'CRM import preview generated',
            'preview' => $preview,
        ]);
    }

    public function importCreators(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'mapping' => ['nullable'],
            'defaultPlatform' => ['nullable', 'string', Rule::in(['instagram', 'tiktok', 'email'])],
            'createInitialTasks' => ['nullable', 'boolean'],
        ]);

        $workbookId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $mapping = $validated['mapping'] ?? [];
        if (is_string($mapping)) {
            $decoded = json_decode($mapping, true);
            $mapping = is_array($decoded) ? $decoded : [];
        }
        try {
            $summary = $this->importer->importCreatorsCsv(
                $workbookId,
                $request->file('file'),
                is_array($mapping) ? $mapping : [],
                ['defaultPlatform' => $validated['defaultPlatform'] ?? null],
            );
        } catch (CrmImportValidationException $exception) {
            return $this->invalidFileResponse($exception);
        }

        $profileIds = (array) ($summary['profileIds'] ?? []);
        unset($summary['profileIds']);
        if (($validated['createInitialTasks'] ?? false) && $profileIds !== []) {
            $summary['taskGeneration'] = $this->taskQueue->generateInitialTasks($workbookId, [
                'profileIds' => $profileIds,
                'limit' => 12,
            ]);
        }

        return response()->json([
            'message' => 'CRM creators imported',
            'summary' => $summary,
        ]);
    }

    private function invalidFileResponse(CrmImportValidationException $exception)
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'errors' => ['file' => [$exception->getMessage()]],
        ], 422);
    }
}
