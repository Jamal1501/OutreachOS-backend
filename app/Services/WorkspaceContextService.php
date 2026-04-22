<?php

namespace App\Services;

use Illuminate\Http\Request;
use RuntimeException;

class WorkspaceContextService
{
    public function resolveWorkbookId(Request $request, ?string $sheetId = null): string
    {
        $workspaceWorkbookId = trim((string) $request->attributes->get('workspace_workbook_id'));
        $workspaceId = trim((string) $request->attributes->get('workspace_id'));
        $explicit = trim((string) $sheetId);

        $defaultWorkbookId = $workspaceWorkbookId !== ''
            ? $workspaceWorkbookId
            : ($workspaceId !== '' ? 'workspace:' . $workspaceId : '');

        if ($defaultWorkbookId === '') {
            throw new RuntimeException('Missing workspace context.');
        }

        if ($explicit === '') {
            return $defaultWorkbookId;
        }

        if (str_starts_with($explicit, 'workspace:') && $explicit !== $defaultWorkbookId) {
            throw new RuntimeException('Requested workbook does not belong to the active workspace.');
        }

        if (!str_starts_with($explicit, 'workspace:') && $workspaceId !== '') {
            return $defaultWorkbookId;
        }

        return $explicit;
    }
}
