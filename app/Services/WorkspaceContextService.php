<?php

namespace App\Services;

use Illuminate\Http\Request;
use RuntimeException;

class WorkspaceContextService
{
    public function resolveWorkbookId(Request $request, ?string $sheetId = null): string
    {
        $explicit = trim((string) $sheetId);
        if ($explicit !== '') {
            return $explicit;
        }

        $workspaceWorkbookId = trim((string) $request->attributes->get('workspace_workbook_id'));
        if ($workspaceWorkbookId !== '') {
            return $workspaceWorkbookId;
        }

        $fallback = trim((string) config('services.google.default_sheet_id'));
        if ($fallback !== '') {
            return $fallback;
        }

        throw new RuntimeException('Missing workspace workbook mapping and GOOGLE_DEFAULT_SHEET_ID.');
    }
}
