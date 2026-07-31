<?php

namespace App\Http\Controllers;

use App\Models\CreatorProfile;
use App\Services\CreatorRelationshipTimelineService;
use App\Services\ProjectResolverService;
use App\Services\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CreatorRelationshipController extends Controller
{
    public function __construct(
        private WorkspaceContextService $workspaceContext,
        private ProjectResolverService $projects,
        private CreatorRelationshipTimelineService $timeline,
    ) {}

    public function index(Request $request, string $id)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $workspaceId = trim((string) $request->attributes->get('workspace_id'));
        abort_if($workspaceId === '', 400, 'Missing workspace context.');

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $project = $this->projects->findByWorkbookId($sheetId);
        abort_if(! $project || (string) $project->workspace_id !== $workspaceId, 404, 'Creator not found');

        $profile = $this->resolveCreatorProfileForRoute((string) $project->id, $id);
        abort_if(! $profile, 404, 'Creator not found');

        return response()->json([
            'message' => 'Creator relationship timeline fetched',
            'data' => [
                'items' => $this->timeline->listForCreator($profile, $workspaceId, (int) ($validated['limit'] ?? 30))->values()->all(),
            ],
        ]);
    }

    public function conversation(Request $request, string $id)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $workspaceId = trim((string) $request->attributes->get('workspace_id'));
        abort_if($workspaceId === '', 400, 'Missing workspace context.');

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $project = $this->projects->findByWorkbookId($sheetId);
        abort_if(! $project || (string) $project->workspace_id !== $workspaceId, 404, 'Creator not found');

        $profile = $this->resolveCreatorProfileForRoute((string) $project->id, $id);
        abort_if(! $profile, 404, 'Creator not found');

        return response()->json([
            'message' => 'Creator conversation fetched',
            'data' => [
                'items' => $this->timeline->listConversationForCreator($profile, (int) ($validated['limit'] ?? 30))->values()->all(),
            ],
        ]);
    }

    public function activeConversations(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $workspaceId = trim((string) $request->attributes->get('workspace_id'));
        abort_if($workspaceId === '', 400, 'Missing workspace context.');

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $project = $this->projects->findByWorkbookId($sheetId);
        abort_if(! $project || (string) $project->workspace_id !== $workspaceId, 404, 'Project not found');

        return response()->json([
            'message' => 'Active conversations fetched',
            'data' => [
                'items' => $this->timeline->listActiveConversations($project, (int) ($validated['limit'] ?? 30))->values()->all(),
            ],
        ]);
    }

    private function resolveCreatorProfileForRoute(string $projectId, string $id): ?CreatorProfile
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        if (Str::startsWith($id, 'crm:')) {
            $rowNumber = (int) substr($id, 4);
            if ($rowNumber > 0) {
                return CreatorProfile::query()
                    ->where('project_id', $projectId)
                    ->where(function ($query) use ($rowNumber) {
                        $query->where('source_reference', 'Creators_CRM:'.$rowNumber)
                            ->orWhere('source_metadata->sheet_row_number', $rowNumber);
                    })
                    ->first();
            }
        }

        if (Str::startsWith($id, 'crmdb:')) {
            $id = substr($id, 6);
        } elseif (Str::startsWith($id, 'profile:')) {
            $id = substr($id, 8);
        }

        if (! Str::isUuid($id)) {
            return null;
        }

        return CreatorProfile::query()
            ->where('project_id', $projectId)
            ->where('id', $id)
            ->first();
    }
}
