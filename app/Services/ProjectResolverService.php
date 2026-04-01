<?php

namespace App\Services;

use App\Models\Project;
use RuntimeException;

class ProjectResolverService
{
    public function findByWorkbookId(string $sheetId): ?Project
    {
        return $this->resolveProject($sheetId, null, false);
    }

    public function resolveByWorkbookId(string $sheetId, ?string $projectName = null): Project
    {
        $project = $this->resolveProject($sheetId, $projectName, true);

        if (!$project) {
            throw new RuntimeException('Project not found for workbook id');
        }

        return $project;
    }

    private function resolveProject(string $sheetId, ?string $projectName = null, bool $allowCreate = true): ?Project
    {
        $sheetId = trim($sheetId);
        if ($sheetId === '') {
            if ($allowCreate) {
                throw new RuntimeException('Missing workbook/sheet id');
            }

            return null;
        }

        $workspaceId = $this->currentWorkspaceId();

        $workspaceProject = Project::query()
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->where('workbook_id', $sheetId)
            ->first();

        if ($workspaceProject) {
            return $workspaceProject;
        }

        $existingProject = Project::query()
            ->where('workbook_id', $sheetId)
            ->first();

        if ($existingProject) {
            if ($workspaceId && empty($existingProject->workspace_id)) {
                $existingProject->workspace_id = $workspaceId;
                $existingProject->save();
            }

            return $existingProject;
        }

        $autoCreate = config('outreach.operational_db.auto_create_project', true);
        if (!$autoCreate || !$allowCreate) {
            return null;
        }

        return Project::create([
            'workspace_id' => $workspaceId,
            'name' => $projectName ?: (
                str_starts_with($sheetId, 'workspace:')
                    ? 'Workspace ' . substr($sheetId, strlen('workspace:'))
                    : 'Workbook ' . substr($sheetId, 0, 8)
            ),
            'workbook_id' => $sheetId,
            'status' => 'active',
            'metadata' => [
                'source' => str_starts_with($sheetId, 'workspace:')
                    ? 'workspace_runtime'
                    : 'google_sheets_runtime',
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
