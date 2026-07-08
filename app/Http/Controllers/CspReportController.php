<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CspReportController extends Controller
{
    public function store(Request $request)
    {
        $payload = $request->json()->all();
        $report = $payload['csp-report'] ?? $payload;

        Log::warning('csp violation reported', [
            'document_uri' => Str::limit((string) ($report['document-uri'] ?? ''), 500, ''),
            'violated_directive' => (string) ($report['violated-directive'] ?? ''),
            'effective_directive' => (string) ($report['effective-directive'] ?? ''),
            'blocked_uri' => Str::limit((string) ($report['blocked-uri'] ?? ''), 500, ''),
            'source_file' => Str::limit((string) ($report['source-file'] ?? ''), 500, ''),
            'line_number' => $report['line-number'] ?? null,
            'disposition' => (string) ($report['disposition'] ?? ''),
            'user_agent' => Str::limit((string) $request->userAgent(), 300, ''),
        ]);

        return response()->json(['received' => true], 204);
    }
}
