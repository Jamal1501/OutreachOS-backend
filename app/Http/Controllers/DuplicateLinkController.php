<?php

namespace App\Http\Controllers;

use App\Models\CreatorProfile;
use App\Models\DuplicateLink;
use App\Models\Project;
use App\Services\AiDuplicateDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class DuplicateLinkController extends Controller
{
    private const ALLOWED_STATUSES = ['pending', 'confirmed', 'rejected', 'merged', 'linked'];

    public function __construct(private AiDuplicateDetectionService $duplicates)
    {
    }

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
            'items' => $this->withCreatorIds($links),
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);

        $validated = $request->validate([
            'projectId' => ['required', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:2', 'max:100'],
        ]);

        $project = Project::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey((string) $validated['projectId'])
            ->firstOrFail();

        $limit = (int) ($validated['limit'] ?? 100);
        $creators = CreatorProfile::query()
            ->with('creator')
            ->where('project_id', $project->id)
            ->where(function ($query) {
                $query->whereNull('lifecycle_state')
                    ->orWhere('lifecycle_state', '!=', 'archived');
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (CreatorProfile $profile) => [
                'id' => (string) $profile->id,
                'handle' => $this->normalizeHandle((string) ($profile->handle ?: $profile->username)),
                'platform' => strtolower(trim((string) $profile->platform)),
                'fullName' => (string) ($profile->creator?->display_name ?: ($profile->source_metadata['full_name'] ?? '')),
                'email' => (string) ($profile->creator?->primary_email ?: ''),
            ])
            ->filter(fn (array $creator) => $creator['handle'] !== '' && $creator['platform'] !== '')
            ->values()
            ->all();

        if (count($creators) < 2) {
            return response()->json([
                'message' => 'Not enough creators to scan.',
                'items' => [],
                'summary' => [
                    'scanned' => count($creators),
                    'created' => 0,
                    'updated' => 0,
                    'matches' => 0,
                ],
            ]);
        }

        $matches = $this->duplicates->detect($creators);
        $created = 0;
        $updated = 0;
        $links = collect();

        foreach ($matches as $match) {
            $pair = $this->normalizedPair($match);
            if ($pair === null) {
                continue;
            }

            $link = DuplicateLink::query()
                ->where('workspace_id', $workspaceId)
                ->where('project_id', $project->id)
                ->where('creator_a_handle', $pair['a']['handle'])
                ->where('creator_a_platform', $pair['a']['platform'])
                ->where('creator_b_handle', $pair['b']['handle'])
                ->where('creator_b_platform', $pair['b']['platform'])
                ->first();

            if (!$link) {
                $link = new DuplicateLink([
                    'workspace_id' => $workspaceId,
                    'project_id' => (string) $project->id,
                    'creator_a_handle' => $pair['a']['handle'],
                    'creator_a_platform' => $pair['a']['platform'],
                    'creator_b_handle' => $pair['b']['handle'],
                    'creator_b_platform' => $pair['b']['platform'],
                    'status' => 'pending',
                ]);
                $created++;
            } else {
                $updated++;
            }

            $link->confidence = (float) ($match['confidence'] ?? 0);
            $link->match_signals = array_values(array_filter((array) ($match['signals'] ?? [])));
            $link->save();
            $links->push($link);
        }

        return response()->json([
            'message' => 'Duplicate scan completed.',
            'items' => $this->withCreatorIds($links),
            'summary' => [
                'scanned' => count($creators),
                'created' => $created,
                'updated' => $updated,
                'matches' => $links->count(),
            ],
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
            'items' => $this->withCreatorIds(collect($created)),
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

    private function normalizedPair(array $match): ?array
    {
        $a = [
            'handle' => $this->normalizeHandle((string) data_get($match, 'creatorA.handle')),
            'platform' => strtolower(trim((string) data_get($match, 'creatorA.platform'))),
        ];
        $b = [
            'handle' => $this->normalizeHandle((string) data_get($match, 'creatorB.handle')),
            'platform' => strtolower(trim((string) data_get($match, 'creatorB.platform'))),
        ];

        if ($a['handle'] === '' || $a['platform'] === '' || $b['handle'] === '' || $b['platform'] === '') {
            return null;
        }
        if ($a['handle'] === $b['handle'] && $a['platform'] === $b['platform']) {
            return null;
        }

        $items = [$a, $b];
        usort($items, fn (array $left, array $right) => strcmp($left['platform'] . '|' . $left['handle'], $right['platform'] . '|' . $right['handle']));

        return ['a' => $items[0], 'b' => $items[1]];
    }

    private function withCreatorIds(Collection $links): array
    {
        if ($links->isEmpty()) {
            return [];
        }

        $projectIds = $links->pluck('project_id')->filter()->unique()->values();
        $profiles = CreatorProfile::query()
            ->whereIn('project_id', $projectIds)
            ->get(['id', 'project_id', 'platform', 'handle', 'username'])
            ->keyBy(fn (CreatorProfile $profile) => $this->profileLookupKey(
                (string) $profile->project_id,
                (string) $profile->platform,
                (string) ($profile->handle ?: $profile->username),
            ));

        return $links->map(function (DuplicateLink $link) use ($profiles) {
            $link->setAttribute('creator_a_id', $profiles->get($this->profileLookupKey((string) $link->project_id, (string) $link->creator_a_platform, (string) $link->creator_a_handle))?->id);
            $link->setAttribute('creator_b_id', $profiles->get($this->profileLookupKey((string) $link->project_id, (string) $link->creator_b_platform, (string) $link->creator_b_handle))?->id);

            return $link;
        })->values()->all();
    }

    private function profileLookupKey(string $projectId, string $platform, string $handle): string
    {
        return $projectId . '|' . strtolower(trim($platform)) . '|' . strtolower($this->normalizeHandle($handle));
    }
}
