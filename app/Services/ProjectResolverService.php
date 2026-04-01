<?php

namespace App\Services;

use App\Models\Project;
use RuntimeException;

class ProjectResolverService
{
    public function findByWorkbookId(string $sheetId): ?Project
    {
        $sheetId = trim($sheetId);
        if ($sheetId === '') {
            return null;
        }

        $workspaceId = $this->currentWorkspaceId();

        $project = Project::query()
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->where('workbook_id', $sheetId)
            ->first();
        if ($project) {
            return $project;
        }

        if (!config('outreach.operational_db.auto_create_project', true)) {
            return null;
        }

        return Project::create([
            'workspace_id' => $workspaceId,
            'name' => str_starts_with($sheetId, 'workspace:')
                ? 'Workspace ' . substr($sheetId, strlen('workspace:'))
                : 'Workbook ' . substr($sheetId, 0, 8),
            'workbook_id' => $sheetId,
            'status' => 'active',
            'metadata' => [
                'source' => str_starts_with($sheetId, 'workspace:') ? 'workspace_runtime' : 'google_sheets_runtime',
            ],
        ]);
    }

    public function resolveByWorkbookId(string $sheetId, ?string $projectName = null): Project
    {
        $sheetId = trim($sheetId);
        if ($sheetId === '') {
            throw new RuntimeException('Missing workbook/sheet id');
        }

        $workspaceId = $this->currentWorkspaceId();

        $project = Project::query()
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->where('workbook_id', $sheetId)
            ->first();
        if ($project) {
            return $project;
        }

        if (!config('outreach.operational_db.auto_create_project', true)) {
            throw new RuntimeException('Project not found for workbook id');
        }

        return Project::create([
            'workspace_id' => $workspaceId,
            'name' => $projectName ?: 'Workbook ' . substr($sheetId, 0, 8),
            'workbook_id' => $sheetId,
            'status' => 'active',
            'metadata' => [
                'source' => 'google_sheets_runtime',
            ],
        ]);
    }


    private function currentWorkspaceId(): ?string
    {
        $workspaceId = request()?->attributes->get('workspace_id');
        $workspaceId = is_string($workspaceId) ? trim($workspaceId) : '';

        return $workspaceId !== '' ? $workspaceId : null;
    }
}
