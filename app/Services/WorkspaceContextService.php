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

        if ($explicit !== '' && str_starts_with($explicit, 'workspace:')) {
            return $explicit;
        }

        if ($workspaceWorkbookId !== '') {
            return $workspaceWorkbookId;
        }

        if ($workspaceId !== '') {
            return 'workspace:' . $workspaceId;
        }

        throw new RuntimeException('Missing workspace context.');
    }
}
