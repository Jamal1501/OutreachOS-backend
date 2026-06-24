<?php

namespace App\Http\Controllers;

use App\Services\CrmFileImportService;
use App\Services\WorkspaceContextService;
use Illuminate\Http\Request;

class CrmImportController extends Controller
{
    public function __construct(
        private WorkspaceContextService $workspaceContext,
        private CrmFileImportService $importer,
    ) {
    }

    public function previewCreators(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        return response()->json([
            'message' => 'CRM import preview generated',
            'preview' => $this->importer->previewCreatorsCsv($request->file('file')),
        ]);
    }

    public function importCreators(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'mapping' => ['nullable'],
        ]);

        $workbookId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $mapping = $validated['mapping'] ?? [];
        if (is_string($mapping)) {
            $decoded = json_decode($mapping, true);
            $mapping = is_array($decoded) ? $decoded : [];
        }
        $summary = $this->importer->importCreatorsCsv($workbookId, $request->file('file'), is_array($mapping) ? $mapping : []);

        return response()->json([
            'message' => 'CRM creators imported',
            'summary' => $summary,
        ]);
    }
}
