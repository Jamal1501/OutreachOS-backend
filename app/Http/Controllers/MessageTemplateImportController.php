<?php

namespace App\Http\Controllers;

use App\Exceptions\CrmImportValidationException;
use App\Models\CrmImportBatch;
use App\Services\CrmImportBatchService;
use App\Services\MessageTemplateImportService;
use App\Services\ProjectResolverService;
use App\Services\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MessageTemplateImportController extends Controller
{
    public function __construct(
        private WorkspaceContextService $workspaceContext,
        private ProjectResolverService $projects,
        private MessageTemplateImportService $importer,
        private CrmImportBatchService $batches,
    ) {}

    public function preview(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'mapping' => ['nullable'],
            'stageMapping' => ['nullable'],
        ]);

        try {
            $preview = $this->importer->preview(
                $request->file('file'),
                $this->arrayInput($validated['mapping'] ?? []),
                $this->arrayInput($validated['stageMapping'] ?? []),
            );
        } catch (CrmImportValidationException $exception) {
            return $this->invalidFileResponse($exception);
        }

        return response()->json(['message' => 'Template import preview generated', 'preview' => $preview]);
    }

    public function import(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'mapping' => ['nullable'],
            'stageMapping' => ['nullable'],
            'defaultPlatform' => ['nullable', Rule::in(['instagram', 'tiktok', 'email'])],
            'overwriteExisting' => ['nullable', 'boolean'],
        ]);
        $workbookId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);

        try {
            $summary = $this->importer->import(
                $workbookId,
                $request->file('file'),
                $this->arrayInput($validated['mapping'] ?? []),
                [
                    'stageMapping' => $this->arrayInput($validated['stageMapping'] ?? []),
                    'defaultPlatform' => $validated['defaultPlatform'] ?? 'instagram',
                    'overwriteExisting' => $validated['overwriteExisting'] ?? false,
                    'createdByUserId' => (string) $request->attributes->get('supabase_user_id'),
                ],
            );
        } catch (CrmImportValidationException $exception) {
            return $this->invalidFileResponse($exception);
        }

        return response()->json(['message' => 'Message templates imported', 'summary' => $summary]);
    }

    public function index(Request $request)
    {
        $workbookId = $this->workspaceContext->resolveWorkbookId($request, $request->query('sheetId'));
        $project = $this->projects->resolveByWorkbookId($workbookId);

        return response()->json([
            'batches' => $this->batches->list(
                (string) $request->attributes->get('workspace_id'),
                (int) $project->id,
                'message_templates',
            ),
        ]);
    }

    public function rollback(Request $request, string $batchId)
    {
        $workbookId = $this->workspaceContext->resolveWorkbookId($request, $request->input('sheetId'));
        $project = $this->projects->resolveByWorkbookId($workbookId);
        $batch = CrmImportBatch::query()
            ->where('workspace_id', (string) $request->attributes->get('workspace_id'))
            ->where('project_id', $project->id)
            ->where('settings->importType', 'message_templates')
            ->findOrFail($batchId);

        try {
            $result = $this->batches->rollback($batch);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Template import rolled back', 'result' => $result]);
    }

    private function invalidFileResponse(CrmImportValidationException $exception)
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'errors' => ['file' => [$exception->getMessage()]],
        ], 422);
    }

    private function arrayInput(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
