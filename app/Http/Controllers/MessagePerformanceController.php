<?php

namespace App\Http\Controllers;

use App\Services\MessagePerformanceService;
use App\Services\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class MessagePerformanceController extends Controller
{
    public function __construct(
        private WorkspaceContextService $workspaceContext,
        private MessagePerformanceService $performance,
    ) {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'platform' => ['nullable', 'string', Rule::in(['instagram', 'tiktok', 'email'])],
            'stage' => ['nullable', 'string', Rule::in(['cold_invite', 'after_accept', 'follow_up', 'negotiation', 'check_in', 'post_confirmation'])],
            'taskType' => ['nullable', 'string'],
            'niche' => ['nullable', 'string'],
            'followerBand' => ['nullable', 'string'],
            'valueTier' => ['nullable', 'string'],
            'creatorProfileId' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $sheetId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $cacheKey = 'message-performance:' . md5($workspaceId . '|' . $sheetId . '|' . json_encode($validated));
        $data = $request->has('_')
            ? $this->performance->summaryForSheet($sheetId, $validated)
            : Cache::remember($cacheKey, now()->addSeconds(90), fn () => $this->performance->summaryForSheet($sheetId, $validated));

        return response()->json([
            'message' => 'Message performance fetched',
            'sheetId' => $sheetId,
            'data' => $data,
        ]);
    }
}
