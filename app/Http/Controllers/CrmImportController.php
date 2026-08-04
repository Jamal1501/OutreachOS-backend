<?php

namespace App\Http\Controllers;

use App\Exceptions\CrmImportValidationException;
use App\Models\CrmImportBatch;
use App\Models\WorkspaceMember;
use App\Services\CrmConversationImportService;
use App\Services\CrmFileImportService;
use App\Services\CrmImportBatchService;
use App\Services\ProjectResolverService;
use App\Services\TaskQueueService;
use App\Services\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CrmImportController extends Controller
{
    public function __construct(
        private WorkspaceContextService $workspaceContext,
        private CrmFileImportService $importer,
        private CrmConversationImportService $conversationImporter,
        private TaskQueueService $taskQueue,
        private CrmImportBatchService $batches,
        private ProjectResolverService $projects,
    ) {}

    public function previewCreators(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'mapping' => ['nullable'],
            'stageMapping' => ['nullable'],
        ]);

        try {
            $preview = $this->importer->previewCreatorsCsv(
                $request->file('file'),
                $this->arrayInput($validated['mapping'] ?? []),
                ['stageMapping' => $this->arrayInput($validated['stageMapping'] ?? [])],
            );
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
            'stageMapping' => ['nullable'],
            'pauseWorkflow' => ['nullable', 'boolean'],
            'assignedUserId' => ['nullable', 'string', 'max:255'],
            'missingNextActionStrategy' => ['nullable', Rule::in(['schedule', 'keep_paused'])],
            'missingNextActionDays' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $workbookId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $mapping = $this->arrayInput($validated['mapping'] ?? []);
        $stageMapping = $this->arrayInput($validated['stageMapping'] ?? []);
        $assignedUserId = trim((string) ($validated['assignedUserId'] ?? '')) ?: null;
        $workspaceId = (string) $request->attributes->get('workspace_id');
        if ($assignedUserId && ! WorkspaceMember::query()->where('workspace_id', $workspaceId)->where('user_id', $assignedUserId)->exists()) {
            return response()->json(['message' => 'The selected assignee is not a member of this workspace.'], 422);
        }
        try {
            $summary = $this->importer->importCreatorsCsv(
                $workbookId,
                $request->file('file'),
                $mapping,
                [
                    'defaultPlatform' => $validated['defaultPlatform'] ?? null,
                    'stageMapping' => $stageMapping,
                    'pauseWorkflow' => $validated['pauseWorkflow'] ?? false,
                    'trackBatch' => $validated['pauseWorkflow'] ?? false,
                    'assignedUserId' => $assignedUserId,
                    'missingNextActionStrategy' => $validated['missingNextActionStrategy'] ?? 'keep_paused',
                    'missingNextActionDays' => $validated['missingNextActionDays'] ?? 3,
                    'createdByUserId' => (string) $request->attributes->get('supabase_user_id'),
                ],
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

    public function previewConversations(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'mapping' => ['nullable'],
        ]);

        try {
            $preview = $this->conversationImporter->preview(
                $request->file('file'),
                $this->arrayInput($validated['mapping'] ?? []),
            );
        } catch (CrmImportValidationException $exception) {
            return $this->invalidFileResponse($exception);
        }

        return response()->json([
            'message' => 'Conversation history preview generated',
            'preview' => $preview,
        ]);
    }

    public function importConversations(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'mapping' => ['nullable'],
            'defaultPlatform' => ['nullable', 'string', Rule::in(['instagram', 'tiktok', 'email'])],
        ]);

        $workbookId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        try {
            $summary = $this->conversationImporter->import(
                $workbookId,
                $request->file('file'),
                $this->arrayInput($validated['mapping'] ?? []),
                [
                    'defaultPlatform' => $validated['defaultPlatform'] ?? null,
                    'createdByUserId' => (string) $request->attributes->get('supabase_user_id'),
                ],
            );
        } catch (CrmImportValidationException $exception) {
            return $this->invalidFileResponse($exception);
        }

        return response()->json([
            'message' => 'Conversation history imported',
            'summary' => $summary,
        ]);
    }

    public function listBatches(Request $request)
    {
        $workbookId = $this->workspaceContext->resolveWorkbookId($request, $request->query('sheetId'));
        $project = $this->projects->resolveByWorkbookId($workbookId);

        return response()->json([
            'batches' => $this->batches->list((string) $request->attributes->get('workspace_id'), (int) $project->id),
        ]);
    }

    public function activateBatch(Request $request, string $batchId)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'assignedUserId' => ['nullable', 'string', 'max:255'],
        ]);
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $assignedUserId = trim((string) ($validated['assignedUserId'] ?? '')) ?: null;
        if ($assignedUserId && ! WorkspaceMember::query()->where('workspace_id', $workspaceId)->where('user_id', $assignedUserId)->exists()) {
            return response()->json(['message' => 'The selected assignee is not a member of this workspace.'], 422);
        }

        $batch = CrmImportBatch::query()->where('workspace_id', $workspaceId)->findOrFail($batchId);
        $workbookId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $project = $this->projects->resolveByWorkbookId($workbookId);
        if ((int) $batch->project_id !== (int) $project->id) {
            return response()->json(['message' => 'This import belongs to a different workspace project.'], 422);
        }

        try {
            $result = $this->batches->activate($batch, $workbookId, $assignedUserId);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Imported workflow activated', 'result' => $result]);
    }

    public function rollbackBatch(Request $request, string $batchId)
    {
        $batch = CrmImportBatch::query()
            ->where('workspace_id', (string) $request->attributes->get('workspace_id'))
            ->findOrFail($batchId);

        try {
            $result = $this->batches->rollback($batch);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'CRM import rolled back', 'result' => $result]);
    }

    public function resumeHeldBatch(Request $request, string $batchId)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $batch = CrmImportBatch::query()->where('workspace_id', $workspaceId)->findOrFail($batchId);
        $workbookId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $project = $this->projects->resolveByWorkbookId($workbookId);
        if ((int) $batch->project_id !== (int) $project->id) {
            return response()->json(['message' => 'This import belongs to a different workspace project.'], 422);
        }

        try {
            $result = $this->batches->resumeHeld($batch, $workbookId, (int) ($validated['days'] ?? 3));
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Held creators scheduled', 'result' => $result]);
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
