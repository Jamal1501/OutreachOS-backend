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

        return Project::where('workbook_id', $sheetId)->first();
    }

    public function resolveByWorkbookId(string $sheetId, ?string $projectName = null): Project
    {
        $sheetId = trim($sheetId);
        if ($sheetId === '') {
            throw new RuntimeException('Missing workbook/sheet id');
        }

        $project = Project::where('workbook_id', $sheetId)->first();
        if ($project) {
            return $project;
        }

        if (!config('outreach.operational_db.auto_create_project', true)) {
            throw new RuntimeException('Project not found for workbook id');
        }

        return Project::create([
            'name' => $projectName ?: 'Workbook ' . substr($sheetId, 0, 8),
            'workbook_id' => $sheetId,
            'status' => 'active',
            'metadata' => [
                'source' => 'google_sheets_runtime',
            ],
        ]);
    }
}
