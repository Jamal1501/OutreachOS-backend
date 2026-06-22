<?php

namespace App\Http\Controllers;

use App\Models\DuplicateLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DuplicateLinkController extends Controller
{
    private const ALLOWED_STATUSES = ['pending', 'confirmed', 'rejected', 'merged', 'linked'];

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $projectId = trim((string) $request->query('projectId', ''));

        $links = DuplicateLink::query()
            ->where('workspace_id', $workspaceId)
            ->when($projectId !== '', fn ($query) => $query->where('project_id', $projectId))
            ->orderByDesc('confidence')
            ->orderByDesc('created_at')
            ->limit(min(max((int) $request->query('limit', 100), 1), 250))
            ->get();

        return response()->json([
            'message' => 'Duplicate links loaded.',
            'items' => $links,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);

        $validated = $request->validate([
            'projectId' => ['required', 'string', 'max:255'],
            'duplicates' => ['required', 'array', 'max:100'],
            'duplicates.*.creatorA.handle' => ['required', 'string', 'max:255'],
            'duplicates.*.creatorA.platform' => ['required', 'string', 'max:80'],
            'duplicates.*.creatorB.handle' => ['required', 'string', 'max:255'],
            'duplicates.*.creatorB.platform' => ['required', 'string', 'max:80'],
            'duplicates.*.confidence' => ['required', 'numeric', 'min:0', 'max:100'],
            'duplicates.*.signals' => ['nullable', 'array', 'max:25'],
            'duplicates.*.signals.*' => ['string', 'max:255'],
        ]);

        $created = [];
        $projectId = trim((string) $validated['projectId']);

        foreach ($validated['duplicates'] as $duplicate) {
            $created[] = DuplicateLink::query()->create([
                'workspace_id' => $workspaceId,
                'project_id' => $projectId,
                'creator_a_handle' => $this->normalizeHandle((string) data_get($duplicate, 'creatorA.handle')),
                'creator_a_platform' => strtolower(trim((string) data_get($duplicate, 'creatorA.platform'))),
                'creator_b_handle' => $this->normalizeHandle((string) data_get($duplicate, 'creatorB.handle')),
                'creator_b_platform' => strtolower(trim((string) data_get($duplicate, 'creatorB.platform'))),
                'confidence' => (float) data_get($duplicate, 'confidence', 0),
                'match_signals' => array_values(array_filter((array) data_get($duplicate, 'signals', []))),
                'status' => 'pending',
            ]);
        }

        return response()->json([
            'message' => 'Duplicate links saved.',
            'items' => $created,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(self::ALLOWED_STATUSES)],
        ]);

        $link = DuplicateLink::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($id)
            ->firstOrFail();

        $status = (string) $validated['status'];
        $link->status = $status;
        if (in_array($status, ['merged', 'linked'], true)) {
            $link->merged_at = now();
        }
        $link->save();

        return response()->json([
            'message' => 'Duplicate link updated.',
            'item' => $link,
        ]);
    }

    private function workspaceId(Request $request): string
    {
        $workspaceId = trim((string) $request->attributes->get('workspace_id'));

        abort_if($workspaceId === '', 400, 'Missing workspace context.');

        return $workspaceId;
    }

    private function normalizeHandle(string $handle): string
    {
        return ltrim(trim($handle), '@');
    }
}
