<?php

namespace App\Services;

use App\Models\CreatorProfile;
use App\Models\OutreachEvent;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OperatorViewService
{
    public function __construct(
        private GoogleSheetsService $sheets,
        private InfluencerScoringService $scoring,
        private CreatorLifecycleService $lifecycle,
        private ProjectResolverService $projects,
    ) {
    }

    public function build(string $sheetId): array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if ($project) {
            return $this->buildFromDatabase($project->id);
        }

        if (Str::startsWith($sheetId, 'workspace:')) {
            return $this->emptyWorkspaceView();
        }

        $creatorRows = $this->safeGetRows($sheetId, 'Creators_CRM');
        $taskRows = $this->safeGetRows($sheetId, 'Task_Queue');
        $outreachRows = $this->safeGetRows($sheetId, 'Outreach_Log');

        $creators = array_map(fn (array $row) => $this->normalizeCreatorCard($row), $creatorRows);
        $duplicates = $this->detectDuplicateWarnings($creators);
        $duplicateByCreator = [];

        foreach ($duplicates as $warning) {
            foreach ($warning['creators'] as $creator) {
                $duplicateByCreator[$creator['id']] = $warning['risk'];
            }
        }

        $tasks = $this->normalizeTasks($taskRows);
        $openTaskByCreator = [];

        foreach ($tasks as $task) {
            if (in_array($task['status'], ['completed', 'skipped'], true)) {
                continue;
            }

            $key = strtolower($task['platform'] . '|' . ltrim($task['handle'], '@'));
            $openTaskByCreator[$key][] = $task;
        }

        foreach ($creators as &$creator) {
            $taskKey = strtolower($creator['platform'] . '|' . ltrim($creator['handle'], '@'));
            $creator['duplicateRisk'] = $duplicateByCreator[$creator['id']] ?? 'low';
            $creator['openTaskCount'] = count($openTaskByCreator[$taskKey] ?? []);
            $creator['recommendedNextAction'] = $this->recommendedNextAction($creator);
        }
        unset($creator);

        $triageStates = ['discovered', 'needs_review', 'enriched', 'duplicate_review_needed'];
        $triageItems = array_values(array_filter(
            $creators,
            fn (array $creator) => in_array($creator['lifecycleState'], $triageStates, true)
        ));

        usort($triageItems, fn (array $a, array $b) =>
            ($b['valueScore'] <=> $a['valueScore'])
            ?: strcmp((string) ($b['addedAt'] ?? ''), (string) ($a['addedAt'] ?? ''))
        );

        $readyStates = ['approved_for_outreach', 'queued'];
        $readyQueue = array_values(array_filter($creators, function (array $creator) use ($readyStates) {
            return in_array($creator['lifecycleState'], $readyStates, true)
                || ($creator['lifecycleState'] === 'enriched' && ($creator['valueScore'] ?? 0) >= 55);
        }));

        usort($readyQueue, fn (array $a, array $b) =>
            ($b['valueScore'] <=> $a['valueScore'])
            ?: (($b['followers'] ?? 0) <=> ($a['followers'] ?? 0))
        );

        $today = now()->toDateString();
        $tasksDueToday = array_values(array_filter($tasks, fn (array $task) =>
            !in_array($task['status'], ['completed', 'skipped'], true)
            && str_starts_with((string) ($task['dueDate'] ?? ''), $today)
        ));

        usort($tasksDueToday, fn (array $a, array $b) =>
            strcmp((string) ($a['dueDate'] ?? ''), (string) ($b['dueDate'] ?? ''))
        );

        $recentActivity = $this->normalizeRecentActivity($outreachRows);

        $outreachSent = count(array_filter($outreachRows, fn (array $row) =>
            Str::contains(Str::upper((string) ($row['Event_Type'] ?? '')), ['SENT', 'OUTREACH'])
        ));

        $replies = count(array_filter($outreachRows, fn (array $row) =>
            Str::contains(Str::upper((string) ($row['Event_Type'] ?? '')), ['REPLY', 'ACCEPTED', 'DEAL_WON'])
        ));

        return [
            'metrics' => [
                'triageCount' => count($triageItems),
                'duplicateWarnings' => count($duplicates),
                'readyForOutreach' => count($readyQueue),
                'tasksDueToday' => count($tasksDueToday),
                'outreachSent' => $outreachSent,
                'repliesReceived' => $replies,
                'replyRate' => $outreachSent > 0 ? round(($replies / $outreachSent) * 100, 1) : 0,
            ],
            'triageItems' => array_slice($triageItems, 0, 12),
            'duplicateWarnings' => array_slice($duplicates, 0, 8),
            'readyQueue' => array_slice($readyQueue, 0, 12),
            'tasksDueToday' => array_slice($tasksDueToday, 0, 12),
            'recentActivity' => array_slice($recentActivity, 0, 12),
        ];
    }

    public function buildDecisionSheet(string $sheetId, int $rowNumber): array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if ($project && CreatorProfile::query()->where('project_id', $project->id)->exists()) {
            return $this->buildDecisionSheetFromDatabase($project->id, $rowNumber);
        }

        $creatorRows = $this->safeGetRows($sheetId, 'Creators_CRM');
        $creatorRow = collect($creatorRows)
            ->first(fn (array $row) => (int) ($row['_row_number'] ?? 0) === $rowNumber);

        if (!$creatorRow) {
            throw new \RuntimeException('Creator not found');
        }

        $creator = $this->normalizeCreatorCard($creatorRow);
        $linkedProfiles = [$creator];

        $duplicates = array_values(array_filter(
            $this->detectDuplicateWarnings([
                $creator,
                ...array_map(fn (array $row) => $this->normalizeCreatorCard($row), $creatorRows),
            ]),
            fn (array $warning) => collect($warning['creators'])
                ->contains(fn (array $item) => $item['id'] === $creator['id'])
        ));

        $allTasks = $this->normalizeTasks($this->safeGetRows($sheetId, 'Task_Queue'));
        $relatedTasks = array_values(array_filter(
            $allTasks,
            fn (array $task) =>
                strtolower($task['platform']) === strtolower($creator['platform'])
                && strtolower(ltrim($task['handle'], '@')) === strtolower(ltrim($creator['handle'], '@'))
        ));

        $timeline = $this->normalizeRecentActivity(
            $this->safeGetRows($sheetId, 'Outreach_Log'),
            $creator['platform'],
            $creator['handle']
        );

        array_unshift($timeline, [
            'id' => 'creator-added-' . $creator['id'],
            'type' => 'creator_added',
            'title' => 'Creator added to CRM',
            'description' => $creator['addedAt'] ? 'Added at ' . $creator['addedAt'] : 'Added to CRM',
            'timestamp' => (string) ($creator['addedAt'] ?? ''),
            'handle' => $creator['handle'],
            'platform' => $creator['platform'],
        ]);

        return $this->buildDecisionPayload($creator, $duplicates, $relatedTasks, $timeline, $linkedProfiles);
    }

public function buildDecisionSheetForProfileId(string $sheetId, string $profileId): array
{
    $project = $this->projects->findByWorkbookId($sheetId);
    if (!$project) {
        throw new \RuntimeException('Project not found');
    }

    $profile = CreatorProfile::query()
        ->with('creator')
        ->where('project_id', $project->id)
        ->where('id', $profileId)
        ->first();

    if (!$profile) {
        throw new \RuntimeException('Creator not found');
    }

    $linkedProfileRows = CreatorProfile::query()
        ->with('creator')
        ->where('project_id', $project->id)
        ->where('creator_id', $profile->creator_id)
        ->orderByDesc('updated_at')
        ->limit(20)
        ->get();

    $creator = $this->normalizeCreatorProfileCard($profile, $linkedProfileRows->count());
    $linkedProfiles = $linkedProfileRows
        ->map(fn (CreatorProfile $item) => $this->normalizeCreatorProfileCard($item, $linkedProfileRows->count()))
        ->values()
        ->all();

    if ($linkedProfiles === []) {
        $linkedProfiles = [$creator];
    }

    $duplicateCandidates = CreatorProfile::query()
        ->with('creator')
        ->where('project_id', $project->id)
        ->where('id', '<>', $profile->id)
        ->where(function (Builder $query) use ($profile) {
            $handle = ltrim(strtolower((string) $profile->handle), '@');
            $email = strtolower(trim((string) ($profile->creator?->primary_email ?? '')));
            $displayName = strtolower(trim((string) ($profile->creator?->display_name ?? '')));

            $query->where(function (Builder $handleQuery) use ($profile, $handle) {
                $handleQuery->whereRaw("LOWER(COALESCE(platform, '')) = ?", [strtolower((string) $profile->platform)])
                    ->whereRaw("LOWER(REPLACE(COALESCE(handle, ''), '@', '')) = ?", [$handle]);
            });

            if ($email !== '') {
                $query->orWhereHas('creator', fn (Builder $creatorQuery) => $creatorQuery->whereRaw("LOWER(COALESCE(primary_email, '')) = ?", [$email]));
            }

            if ($displayName !== '') {
                $query->orWhereHas('creator', fn (Builder $creatorQuery) => $creatorQuery->whereRaw("LOWER(COALESCE(display_name, '')) = ?", [$displayName]));
            }
        })
        ->limit(25)
        ->get()
        ->map(fn (CreatorProfile $item) => $this->normalizeCreatorProfileCard($item))
        ->values()
        ->all();

    $duplicates = $this->detectDuplicateWarnings([$creator, ...$duplicateCandidates]);

    $relatedTasks = $this->normalizeDbTasks(
        Task::query()
            ->where('project_id', $project->id)
            ->where(function (Builder $query) use ($profile, $creator) {
                $handle = ltrim((string) ($creator['handle'] ?? ''), '@');
                $query->where('creator_profile_id', $profile->id)
                    ->orWhere(function (Builder $taskQuery) use ($creator, $handle) {
                        $taskQuery->where('platform', strtolower((string) ($creator['platform'] ?? 'instagram')))
                            ->where(function (Builder $handleQuery) use ($creator, $handle) {
                                $handleQuery->where('handle', (string) ($creator['handle'] ?? ''))
                                    ->orWhere('handle', $handle)
                                    ->orWhere('handle', '@' . $handle);
                            });
                    });
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->all()
    );

    $timeline = $this->normalizeDbRecentActivity(
        $this->creatorOutreachEventQuery($project->id, $profile, $creator)
            ->orderByDesc('sent_at')
            ->limit(30)
            ->get()
            ->all(),
        $creator['platform'],
        $creator['handle']
    );

    array_unshift($timeline, [
        'id' => 'creator-added-' . $creator['id'],
        'type' => 'creator_added',
        'title' => 'Creator added to CRM',
        'description' => $creator['addedAt'] ? 'Added at ' . $creator['addedAt'] : 'Added to CRM',
        'timestamp' => (string) ($creator['addedAt'] ?? ''),
        'handle' => $creator['handle'],
        'platform' => $creator['platform'],
    ]);

    return $this->buildDecisionPayload($creator, $duplicates, $relatedTasks, $timeline, $linkedProfiles);
}

    private function buildFromDatabase(int $projectId): array
    {
        $profiles = CreatorProfile::query()
            ->with('creator')
            ->where('project_id', $projectId)
            ->get();

        $linkedProfileCounts = CreatorProfile::query()
            ->where('project_id', $projectId)
            ->whereNotNull('creator_id')
            ->selectRaw('creator_id, COUNT(*) as aggregate')
            ->groupBy('creator_id')
            ->pluck('aggregate', 'creator_id');

        $creators = $profiles
            ->map(function (CreatorProfile $profile) use ($linkedProfileCounts) {
                return $this->normalizeCreatorProfileCard($profile, (int) ($linkedProfileCounts[$profile->creator_id] ?? 1));
            })
            ->values()
            ->all();

        $duplicates = $this->detectDuplicateWarnings($creators);
        $duplicateByCreator = [];

        foreach ($duplicates as $warning) {
            foreach ($warning['creators'] as $creator) {
                $duplicateByCreator[$creator['id']] = $warning['risk'];
            }
        }

        $tasks = $this->normalizeDbTasks(
            Task::query()
                ->with('creatorProfile')
                ->where('project_id', $projectId)
                ->whereNotIn('status', ['COMPLETED', 'DONE', 'SKIPPED', 'ARCHIVED'])
                ->orderBy('due_at')
                ->get()
                ->all()
        );

        $openTaskByCreator = [];
        foreach ($tasks as $task) {
            if (in_array($task['status'], ['completed', 'skipped'], true)) {
                continue;
            }
            $key = strtolower($task['platform'] . '|' . ltrim($task['handle'], '@'));
            $openTaskByCreator[$key][] = $task;
        }

        foreach ($creators as &$creator) {
            $taskKey = strtolower($creator['platform'] . '|' . ltrim($creator['handle'], '@'));
            $creator['duplicateRisk'] = $duplicateByCreator[$creator['id']] ?? 'low';
            $creator['openTaskCount'] = count($openTaskByCreator[$taskKey] ?? []);
            $creator['recommendedNextAction'] = $this->recommendedNextAction($creator);
        }
        unset($creator);

        $triageStates = ['discovered', 'needs_review', 'enriched', 'duplicate_review_needed'];
        $triageItems = array_values(array_filter($creators, fn (array $creator) => in_array($creator['lifecycleState'], $triageStates, true)));
        usort($triageItems, fn (array $a, array $b) => ($b['valueScore'] <=> $a['valueScore']) ?: strcmp((string) ($b['addedAt'] ?? ''), (string) ($a['addedAt'] ?? '')));

        $readyStates = ['approved_for_outreach', 'queued'];
        $readyQueue = array_values(array_filter($creators, function (array $creator) use ($readyStates) {
            return in_array($creator['lifecycleState'], $readyStates, true)
                || ($creator['lifecycleState'] === 'enriched' && ($creator['valueScore'] ?? 0) >= 55);
        }));
        usort($readyQueue, fn (array $a, array $b) => ($b['valueScore'] <=> $a['valueScore']) ?: (($b['followers'] ?? 0) <=> ($a['followers'] ?? 0)));

        $today = now()->toDateString();
        $tasksDueToday = array_values(array_filter($tasks, fn (array $task) => !in_array($task['status'], ['completed', 'skipped'], true) && str_starts_with((string) ($task['dueDate'] ?? ''), $today)));
        usort($tasksDueToday, fn (array $a, array $b) => strcmp((string) ($a['dueDate'] ?? ''), (string) ($b['dueDate'] ?? '')));

        $recentActivity = $this->normalizeDbRecentActivity(
            OutreachEvent::query()
                ->where('project_id', $projectId)
                ->orderByDesc('sent_at')
                ->limit(24)
                ->get()
                ->all()
        );

        $outreachSent = OutreachEvent::query()
            ->where('project_id', $projectId)
            ->whereIn(DB::raw('UPPER(event_type)'), $this->strictOutreachSentEventTypes())
            ->count();

        $replies = OutreachEvent::query()
            ->where('project_id', $projectId)
            ->whereIn(DB::raw('UPPER(event_type)'), $this->strictReplyEventTypes())
            ->count();

        return [
            'metrics' => [
                'triageCount' => count($triageItems),
                'duplicateWarnings' => count($duplicates),
                'readyForOutreach' => count($readyQueue),
                'tasksDueToday' => count($tasksDueToday),
                'outreachSent' => $outreachSent,
                'repliesReceived' => $replies,
                'replyRate' => $outreachSent > 0 ? round(($replies / $outreachSent) * 100, 1) : 0,
            ],
            'triageItems' => array_slice($triageItems, 0, 12),
            'duplicateWarnings' => array_slice($duplicates, 0, 8),
            'readyQueue' => array_slice($readyQueue, 0, 12),
            'tasksDueToday' => array_slice($tasksDueToday, 0, 12),
            'recentActivity' => array_slice($recentActivity, 0, 12),
        ];
    }

    private function buildDecisionSheetFromDatabase(int $projectId, int $rowNumber): array
    {
        $profile = CreatorProfile::query()
            ->with('creator')
            ->where('project_id', $projectId)
            ->where(function (Builder $query) use ($rowNumber) {
                $query->where('source_reference', 'Creators_CRM:' . $rowNumber)
                    ->orWhere('source_metadata->sheet_row_number', $rowNumber);
            })
            ->first();

        if (!$profile) {
            throw new \RuntimeException('Creator not found');
        }

        $creator = $this->normalizeCreatorProfileCard($profile);
        $allCreators = CreatorProfile::query()->with('creator')->where('project_id', $projectId)->get()->map(fn (CreatorProfile $item) => $this->normalizeCreatorProfileCard($item))->values()->all();
        $duplicates = array_values(array_filter(
            $this->detectDuplicateWarnings($allCreators),
            fn (array $warning) => collect($warning['creators'])->contains(fn (array $item) => $item['id'] === $creator['id'])
        ));

        $linkedProfiles = array_values(array_filter(
            $allCreators,
            fn (array $item) => ($item['creatorIdentityId'] ?? null) !== null
                && ($creator['creatorIdentityId'] ?? null) !== null
                && (string) $item['creatorIdentityId'] === (string) $creator['creatorIdentityId']
        ));

        if ($linkedProfiles === []) {
            $linkedProfiles = [$creator];
        }

        $relatedTasks = $this->normalizeDbTasks(
            Task::query()
                ->where('project_id', $projectId)
                ->where(function (Builder $query) use ($profile, $creator) {
                    $handle = ltrim((string) ($creator['handle'] ?? ''), '@');
                    $query->where('creator_profile_id', $profile->id)
                        ->orWhere(function (Builder $taskQuery) use ($creator, $handle) {
                            $taskQuery->where('platform', strtolower((string) ($creator['platform'] ?? 'instagram')))
                                ->where(function (Builder $handleQuery) use ($creator, $handle) {
                                    $handleQuery->where('handle', (string) ($creator['handle'] ?? ''))
                                        ->orWhere('handle', $handle)
                                        ->orWhere('handle', '@' . $handle);
                                });
                        });
                })
                ->orderByDesc('created_at')
                ->get()
                ->all()
        );

        $timeline = $this->normalizeDbRecentActivity(
            $this->creatorOutreachEventQuery($projectId, $profile, $creator)
                ->orderByDesc('sent_at')
                ->get()
                ->all(),
            $creator['platform'],
            $creator['handle']
        );

        array_unshift($timeline, [
            'id' => 'creator-added-' . $creator['id'],
            'type' => 'creator_added',
            'title' => 'Creator added to CRM',
            'description' => $creator['addedAt'] ? 'Added at ' . $creator['addedAt'] : 'Added to CRM',
            'timestamp' => (string) ($creator['addedAt'] ?? ''),
            'handle' => $creator['handle'],
            'platform' => $creator['platform'],
        ]);

        return $this->buildDecisionPayload($creator, $duplicates, $relatedTasks, $timeline, $linkedProfiles);
    }

    private function buildDecisionPayload(array $creator, array $duplicates, array $relatedTasks, array $timeline, array $linkedProfiles = []): array
    {
        $hardDisqualifiers = [];
        if (($creator['duplicateRisk'] ?? 'low') === 'high' || count($duplicates) > 0) {
            $hardDisqualifiers[] = 'Duplicate risk needs operator review';
        }
        if (($creator['followers'] ?? 0) <= 0) {
            $hardDisqualifiers[] = 'No follower data';
        }
        if (trim((string) ($creator['profileUrl'] ?? '')) === '' && trim((string) ($creator['email'] ?? '')) === '') {
            $hardDisqualifiers[] = 'No obvious contact path';
        }
        if (in_array($creator['lifecycleState'], ['lost', 'declined', 'archived'], true)) {
            $hardDisqualifiers[] = 'Creator already in terminal state';
        }

        $sourceHashtags = array_values(array_filter((array) ($creator['sourceHashtags'] ?? [])));
        $lastContentDate = trim((string) ($creator['lastContentDate'] ?? ''));
        $lastContactDate = trim((string) ($creator['lastContactDate'] ?? ''));
        $timelineLastOutreach = $this->latestTimelineTimestamp($timeline, $this->strictOutreachSentEventTypes());
        if ($timelineLastOutreach !== '') {
            $lastContactDate = $timelineLastOutreach;
        }

        $confidenceReasons = [];
        if (($creator['followers'] ?? 0) > 0) {
            $confidenceReasons[] = 'Follower data exists';
        }
        if ($creator['email']) {
            $confidenceReasons[] = 'Direct email available';
        }
        if (($creator['engagementRate'] ?? 0) > 0) {
            $confidenceReasons[] = 'Engagement data exists';
        }
        if (($creator['evidence']['followers']['source'] ?? 'unknown') !== 'unknown') {
            $confidenceReasons[] = 'Key fields have evidence source';
        }
        if (($creator['valueScore'] ?? 0) >= 50) {
            $confidenceReasons[] = 'Value score is usable for prioritization';
        }
        if ($lastContactDate !== '' || $this->latestTimelineTimestamp($timeline, $this->strictOutreachSentEventTypes()) !== '') {
            $confidenceReasons[] = 'Relationship history is tracked';
        }

        $nextBestAction = $this->nextBestAction($creator, $relatedTasks, $timeline, $hardDisqualifiers);

        return [
            'creator' => $creator,
            'linkedProfiles' => array_values($linkedProfiles),
            'decisionSheet' => [
                'whoTheyAre' => trim(implode(' · ', array_filter([
                    $creator['fullName'] ?: $creator['handle'],
                    ucfirst($creator['platform']),
                    $creator['niche'] ?: null,
                ]))),
                'whyTheyFit' => array_values(array_filter([
                    ($creator['valueScore'] ?? 0) >= 70 ? 'High-value creator by current score' : null,
                    ($creator['engagementRate'] ?? 0) >= 2 ? 'Engagement rate is usable' : null,
                    $creator['email'] ? 'Direct contact route exists' : null,
                    $creator['niche'] ? 'Niche present: ' . $creator['niche'] : null,
                    $lastContentDate !== '' ? 'Recent source activity captured' : null,
                    count($sourceHashtags) > 0 ? 'Discovered through #' . implode(', #', array_slice($sourceHashtags, 0, 3)) : null,
                ])),
                'confidenceSummary' => [
                    'score' => $this->confidenceScore($creator, $confidenceReasons, $hardDisqualifiers, $timeline),
                    'label' => count($hardDisqualifiers) > 1
                        ? 'fragile'
                        : (count($confidenceReasons) >= 4
                            ? 'strong'
                            : (count($confidenceReasons) >= 3 ? 'usable' : 'fragile')),
                    'reasons' => $confidenceReasons,
                ],
                'lastRealActivity' => $this->lastRealSignalLabel($creator, $timeline),
                'lastOutreach' => $lastContactDate !== '' ? $lastContactDate : 'Not reached out yet',
                'duplicateRisk' => [
                    'level' => count($duplicates) > 0 ? ($duplicates[0]['risk'] ?? 'medium') : 'low',
                    'reasons' => count($duplicates) > 0
                        ? array_values(array_unique(array_filter(array_map(fn (array $warning) => (string) ($warning['reason'] ?? ''), $duplicates))))
                        : ['No duplicate warning currently surfaced'],
                    'relatedCreators' => count($duplicates) > 0
                        ? array_values(array_unique(array_filter(array_merge(...array_map(fn (array $warning) => array_map(fn (array $item) => $item['handle'] . ' (' . $item['platform'] . ')', $warning['creators']), $duplicates)))))
                        : [],
                ],
                'recommendedNextAction' => (string) ($nextBestAction['reason'] ?? $nextBestAction['title'] ?? ''),
                'nextBestAction' => $nextBestAction,
                'hardDisqualifiers' => $hardDisqualifiers,
                'operatorNotes' => (string) ($creator['notes'] ?? ''),
                'timeline' => array_slice($timeline, 0, 20),
                'relatedTasks' => array_slice($relatedTasks, 0, 10),
                'creatorRoi' => $this->creatorRoi($creator, $timeline),
            ],
        ];
    }

    private function safeGetRows(string $sheetId, string $sheetName): array
    {
        if (Str::startsWith($sheetId, 'workspace:')) {
            return [];
        }

        try {
            return $this->sheets->getRows($sheetId, $sheetName);
        } catch (\Throwable $e) {
            Log::warning('OperatorViewService sheet load failed', [
                'sheetId' => $sheetId,
                'sheetName' => $sheetName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function emptyWorkspaceView(): array
    {
        return [
            'metrics' => [
                'triageCount' => 0,
                'duplicateWarnings' => 0,
                'readyForOutreach' => 0,
                'tasksDueToday' => 0,
                'outreachSent' => 0,
                'repliesReceived' => 0,
                'replyRate' => 0,
            ],
            'triageItems' => [],
            'duplicateWarnings' => [],
            'readyQueue' => [],
            'tasksDueToday' => [],
            'recentActivity' => [],
        ];
    }

    private function normalizeCreatorCard(array $row): array
    {
        $platform = Str::lower((string) ($row['Platform'] ?? 'instagram'));
        $enrichmentStatus = $this->normalizeEnrichmentStatus($row);
        $state = $this->lifecycle->normalizeState((string) ($row['Status'] ?? ''), $enrichmentStatus);
        $score = is_numeric((string) ($row['Value_Score'] ?? ''))
            ? (float) $row['Value_Score']
            : $this->scoring->score($row);
        $followers = $this->toInt($row['Followers'] ?? null);
        $engagementRate = $this->toFloat($row['Engagement_Rate_%'] ?? null);
        $score = $this->presentableValueScore($score, $followers, $engagementRate);

        $addedAt = $this->extractTaggedValue((string) ($row['Notes'] ?? ''), 'added_to_crm_at') ?? '';
        $lastContentDate = trim((string) ($row['Last_Content_Date'] ?? ''));
        $lastContentDate = str_starts_with($lastContentDate, '=') ? '' : $lastContentDate;

        $identityId = $this->extractTaggedValue((string) ($row['Notes'] ?? ''), 'creator_identity_id') ?? '';
        $linkedProfiles = $this->extractTaggedValue((string) ($row['Notes'] ?? ''), 'linked_profiles') ?? '';
        $linkedProfileCount = $linkedProfiles !== '' ? count(array_filter(explode(',', $linkedProfiles))) : ($identityId !== '' ? 1 : 0);

        return [
            'id' => 'crm:' . (int) ($row['_row_number'] ?? 0),
            'rowNumber' => (int) ($row['_row_number'] ?? 0),
            'platform' => $platform,
            'handle' => (string) ($row['Handle'] ?? ''),
            'fullName' => (string) ($row['Name'] ?? ''),
            'followers' => $followers,
            'engagementRate' => $engagementRate,
            'email' => (string) ($row['Contact_Email'] ?? ''),
            'profileUrl' => (string) ($row['DM_Link'] ?? ''),
            'status' => $state,
            'lifecycleState' => $state,
            'enrichmentStatus' => $enrichmentStatus,
            'niche' => (string) ($row['Niche_Category'] ?? ''),
            'lastContactDate' => (string) ($row['DM_Sent_Date'] ?? ''),
            'responseDate' => (string) ($row['Response_Date'] ?? ''),
            'lastContentDate' => $lastContentDate,
            'notes' => (string) ($row['Notes'] ?? ''),
            'addedAt' => $addedAt,
            'valueScore' => (int) round($score),
            'valueTier' => Str::lower($this->scoring->tier($score)),
            'preferredChannel' => (string) ($row['Preferred_Channel'] ?? ''),
            'duplicateRisk' => trim((string) ($row['Duplicate_Flag'] ?? '')) !== '' ? 'medium' : 'low',
            'creatorIdentityId' => $identityId !== '' ? $identityId : null,
            'linkedProfileCount' => $linkedProfileCount,
            'evidence' => [
                'followers' => [
                    'source' => $this->lifecycle->inferEvidenceSource($row, 'followers'),
                    'freshness' => $this->lifecycle->freshnessLabel($addedAt),
                ],
                'engagementRate' => [
                    'source' => $this->lifecycle->inferEvidenceSource($row, 'engagementRate'),
                    'freshness' => $this->lifecycle->freshnessLabel($addedAt),
                ],
                'email' => [
                    'source' => $this->lifecycle->inferEvidenceSource($row, 'email'),
                    'freshness' => $this->lifecycle->freshnessLabel($addedAt),
                ],
                'status' => [
                    'source' => Str::contains(Str::lower((string) ($row['Notes'] ?? '')), 'manual_transition=')
                        ? 'human edited'
                        : 'system',
                    'freshness' => $this->lifecycle->freshnessLabel(
                        (string) ($row['Response_Date'] ?? $row['DM_Sent_Date'] ?? '')
                    ),
                ],
            ],
        ];
    }

    private function normalizeCreatorProfileCard(CreatorProfile $profile, ?int $linkedProfileCount = null): array
    {
        $creator = $profile->creator;
        $rawState = (string) ($profile->lifecycle_state ?: $profile->status ?: '');
        $state = $this->lifecycle->normalizeState($rawState, 'enriched');
        $sourceRowNumber = (int) (($profile->source_metadata['sheet_row_number'] ?? 0) ?: 0);
        $addedAt = optional($profile->created_at)?->toDateTimeString() ?? '';

        $sourceMetadata = is_array($profile->source_metadata) ? $profile->source_metadata : [];
        $creatorMetadata = is_array($creator?->metadata) ? $creator->metadata : [];

        return [
            'id' => $sourceRowNumber > 0 ? 'crm:' . $sourceRowNumber : 'profile:' . $profile->id,
            'projectId' => (string) $profile->project_id,
            'platform' => strtolower((string) ($profile->platform ?: 'instagram')),
'handle' => (string) ($profile->handle ?: ''),
'fullName' => (string) ($creator?->display_name ?: $profile->username ?: ''),
'avatarUrl' => (string) ($profile->profile_pic_url ?: ''),
'followers' => (int) ($profile->followers_count ?? 0),
'engagementRate' => $profile->engagement_rate_pct !== null ? (float) $profile->engagement_rate_pct : null,
'email' => (string) ($creator?->primary_email ?: ''),
'profileUrl' => (string) ($profile->profile_url ?: $profile->dm_link ?: ''),
'status' => $state,
            'lifecycleState' => $state,
            'enrichmentStatus' => 'enriched',
            'niche' => (string) ($creator?->niche_category ?: ''),
            'lastContactDate' => optional($profile->last_outreach_at ?: $profile->dm_sent_at)?->toDateTimeString() ?? '',
            'responseDate' => optional($profile->responded_at)?->toDateTimeString() ?? '',
            'lastContentDate' => optional($profile->last_content_at)?->toDateTimeString() ?? '',
            'notes' => (string) ($creator?->notes ?: ''),
            'addedAt' => $addedAt,
            'valueScore' => $this->presentableValueScore((float) ($profile->value_score ?? 0), (int) ($profile->followers_count ?? 0), $profile->engagement_rate_pct !== null ? (float) $profile->engagement_rate_pct : null),
            'valueTier' => Str::lower($this->scoring->tier($this->presentableValueScore((float) ($profile->value_score ?? 0), (int) ($profile->followers_count ?? 0), $profile->engagement_rate_pct !== null ? (float) $profile->engagement_rate_pct : null))),
            'preferredChannel' => (string) ($profile->preferred_channel ?: ''),
            'duplicateRisk' => $profile->duplicate_flag ? 'medium' : 'low',
            'creatorIdentityId' => (string) ($creator?->external_identity_key ?: ''),
            'linkedProfileCount' => $linkedProfileCount ?? 1,
            'openTaskCount' => 0,
            'sourcePostUrl' => (string) (($sourceMetadata['source_post_url'] ?? $creatorMetadata['latest_source_post_url'] ?? '') ?: ''),
            'sourcePostUrls' => array_values(array_filter((array) ($sourceMetadata['source_post_urls'] ?? $creatorMetadata['source_post_urls'] ?? []))),
            'sourceMetricType' => (string) (($sourceMetadata['source_metric_type'] ?? $creatorMetadata['latest_source_metric_type'] ?? '') ?: ''),
            'sourceMetricValue' => is_numeric((string) ($sourceMetadata['source_metric_value'] ?? $creatorMetadata['latest_source_metric_value'] ?? null)) ? (int) ($sourceMetadata['source_metric_value'] ?? $creatorMetadata['latest_source_metric_value']) : null,
            'matchedPostCount' => is_numeric((string) ($sourceMetadata['matched_post_count'] ?? $creatorMetadata['matched_post_count'] ?? null)) ? (int) ($sourceMetadata['matched_post_count'] ?? $creatorMetadata['matched_post_count']) : null,
            'sourceHashtags' => array_values(array_filter((array) ($sourceMetadata['source_hashtags'] ?? $creatorMetadata['source_hashtags'] ?? []))),
            'evidence' => [
                'followers' => [
                    'source' => (string) ($profile->source_provider ?: 'database'),
                    'freshness' => $this->lifecycle->freshnessLabel($addedAt),
                ],
                'engagementRate' => [
                    'source' => (string) ($profile->source_provider ?: 'database'),
                    'freshness' => $this->lifecycle->freshnessLabel($addedAt),
                ],
                'email' => [
                    'source' => $creator?->primary_email ? 'database' : 'unknown',
                    'freshness' => $this->lifecycle->freshnessLabel($addedAt),
                ],
                'status' => [
                    'source' => 'database',
                    'freshness' => $this->lifecycle->freshnessLabel(optional($profile->updated_at)?->toDateTimeString() ?? $addedAt),
                ],
            ],
        ];
    }


    private function sanitizeStoredMessageDraft(string $value): string
    {
        $clean = trim(str_replace('—', ' - ', $value));
        if ($clean === '') {
            return '';
        }

        $normalized = strtolower($clean);
        $normalized = preg_replace('/@[a-z0-9_.-]+/i', '@handle', $normalized) ?: $normalized;
        $normalized = preg_replace('/\{\{\s*(handle|name)\s*\}\}/i', '{{token}}', $normalized) ?: $normalized;
        $normalized = preg_replace('/[^a-z0-9@{}]+/i', ' ', $normalized) ?: $normalized;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?: $normalized);

        foreach ([
            'relevant creator partnership fit here open to a short idea',
            'relevant fit between your audience and this campaign open to a short idea',
            'quick follow up from my last note worth sending a short idea or should i leave it',
            'specific angle here is strong this is the kind of post worth saving',
            'i think there could be a strong creator brand fit around',
            'strong creator brand fit around',
            'handle i am checking whether there is a relevant fit',
            'name i am checking whether there is a relevant creator partnership fit',
        ] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return '';
            }
        }

        return $clean;
    }

    private function normalizeDbTasks(array $tasks): array
    {
        $items = array_map(function (Task $task) {
            $status = strtoupper(trim((string) ($task->status ?: 'PENDING')));

return [
    'id' => (string) ($task->external_task_key ?: $task->id),
    'creatorProfileId' => (string) ($task->creator_profile_id ?: ''),
    'type' => (string) $task->task_type,
    'platform' => Str::lower((string) ($task->platform ?: 'instagram')),
    'handle' => (string) ($task->handle ?: ''),
    'status' => match ($status) {
        'DONE', 'COMPLETED' => 'completed',
        'SKIPPED' => 'skipped',
        'IN_PROGRESS' => 'in_progress',
        'SNOOZED' => 'snoozed',
        default => 'pending',
    },
    'priority' => Str::lower((string) ($task->priority ?: 'medium')),
    'dueDate' => optional($task->due_at)?->toDateTimeString() ?? '',
    'createdAt' => optional($task->created_at)?->toDateTimeString() ?? '',
    'completedAt' => optional($task->completed_at)?->toDateTimeString() ?? '',
    'messageText' => $this->sanitizeStoredMessageDraft((string) ($task->message_draft ?: '')),
    'profileUrl' => (string) ($task->open_url ?: ''),
    'profilePicUrl' => (string) ($task->creatorProfile?->profile_pic_url ?: ''),
    'notes' => (string) ($task->notes ?: ''),
];
        }, $tasks);

        usort($items, fn (array $a, array $b) => strcmp((string) ($a['dueDate'] ?? ''), (string) ($b['dueDate'] ?? '')));
        return $items;
    }

    private function normalizeDbRecentActivity(array $events, ?string $platform = null, ?string $handle = null): array
    {
        $items = [];

        foreach ($events as $event) {
            if (!$event instanceof OutreachEvent) {
                continue;
            }
            $rowPlatform = Str::lower((string) ($event->platform ?: ''));
            $rowHandle = (string) ($event->handle ?: '');
            if ($platform !== null && $rowPlatform !== Str::lower($platform)) {
                continue;
            }
            if ($handle !== null && strtolower(ltrim($rowHandle, '@')) !== strtolower(ltrim($handle, '@'))) {
                continue;
            }
            $eventType = Str::upper((string) ($event->event_type ?: 'EVENT'));
            $items[] = [
                'id' => (string) ($event->external_event_key ?: $event->id),
                'type' => Str::lower($eventType),
                'title' => str_replace('_', ' ', Str::headline($eventType)),
                'description' => (string) ($event->notes ?: ''),
                'timestamp' => optional($event->sent_at)?->toDateTimeString() ?? optional($event->created_at)?->toDateTimeString() ?? '',
                'handle' => $rowHandle,
                'platform' => $rowPlatform,
                'status' => (string) ($event->status ?: ''),
            ];
        }

        usort($items, fn (array $a, array $b) => strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? '')));
        return $items;
    }

    private function normalizeTasks(array $taskRows): array
    {
        $items = array_map(function (array $row) {
            $status = strtoupper(trim((string) ($row['Status'] ?? 'PENDING')));

            return [
                'id' => (string) ($row['Task_ID'] ?? ''),
                'type' => (string) ($row['Task_Type'] ?? ''),
                'platform' => Str::lower((string) ($row['Platform'] ?? 'instagram')),
                'handle' => (string) ($row['Handle'] ?? ''),
                'status' => match ($status) {
                    'DONE', 'COMPLETED' => 'completed',
                    'SKIPPED' => 'skipped',
                    'IN_PROGRESS' => 'in_progress',
                    'SNOOZED' => 'snoozed',
                    default => 'pending',
                },
                'priority' => Str::lower((string) ($row['Priority'] ?? 'medium')),
                'dueDate' => (string) ($row['Due_At'] ?? ''),
                'createdAt' => (string) ($row['Created_At'] ?? ''),
                'completedAt' => (string) ($row['Completed_At'] ?? ''),
                'messageText' => $this->sanitizeStoredMessageDraft((string) ($row['Message_Draft'] ?? '')),
                'profileUrl' => (string) ($row['Open_URL'] ?? ''),
                'notes' => (string) ($row['Notes'] ?? ''),
            ];
        }, $taskRows);

        usort($items, fn (array $a, array $b) =>
            strcmp((string) ($a['dueDate'] ?? ''), (string) ($b['dueDate'] ?? ''))
        );

        return $items;
    }

    private function normalizeRecentActivity(array $rows, ?string $platform = null, ?string $handle = null): array
    {
        $items = [];

        foreach ($rows as $index => $row) {
            $rowPlatform = Str::lower((string) ($row['Platform'] ?? ''));
            $rowHandle = (string) ($row['Handle'] ?? '');

            if ($platform !== null && $rowPlatform !== Str::lower($platform)) {
                continue;
            }

            if ($handle !== null && strtolower(ltrim($rowHandle, '@')) !== strtolower(ltrim($handle, '@'))) {
                continue;
            }

            $eventType = Str::upper((string) ($row['Event_Type'] ?? 'EVENT'));

            $items[] = [
                'id' => (string) ($row['Event_ID'] ?? ('evt-' . $index)),
                'type' => Str::lower($eventType),
                'title' => str_replace('_', ' ', Str::headline($eventType)),
                'description' => (string) ($row['Notes'] ?? ''),
                'timestamp' => (string) ($row['Sent_At'] ?? ''),
                'handle' => $rowHandle,
                'platform' => $rowPlatform,
                'status' => (string) ($row['Status'] ?? ''),
            ];
        }

        usort($items, fn (array $a, array $b) =>
            strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? ''))
        );

        return $items;
    }

    private function detectDuplicateWarnings(array $creators): array
    {
        $groups = [];

        foreach ($creators as $creator) {
            $handleKey = strtolower(ltrim((string) ($creator['handle'] ?? ''), '@'));
            $emailKey = strtolower(trim((string) ($creator['email'] ?? '')));
            $nameKey = strtolower(trim((string) ($creator['fullName'] ?? '')));

            if ($handleKey !== '') {
                $groups['handle:' . $handleKey][] = $creator;
            }
            if ($emailKey !== '') {
                $groups['email:' . $emailKey][] = $creator;
            }
            if ($nameKey !== '') {
                $groups['name:' . $nameKey][] = $creator;
            }
        }

        $warnings = [];

        foreach ($groups as $key => $items) {
            $uniqueIds = array_values(array_unique(array_map(fn (array $item) => $item['id'], $items)));
            if (count($uniqueIds) < 2) {
                continue;
            }

            $reason = str_starts_with($key, 'email:')
                ? 'Shared email detected'
                : (str_starts_with($key, 'handle:') ? 'Shared handle detected' : 'Shared full name detected');

            $risk = str_starts_with($key, 'email:') || str_starts_with($key, 'handle:') ? 'high' : 'medium';

            $creatorLookup = [];
            foreach ($items as $item) {
                $creatorLookup[$item['id']] = [
                    'id' => $item['id'],
                    'handle' => $item['handle'],
                    'platform' => $item['platform'],
                    'status' => $item['lifecycleState'],
                ];
            }

            $warnings[] = [
                'key' => $key,
                'risk' => $risk,
                'reason' => $reason,
                'creators' => array_values($creatorLookup),
            ];
        }

        usort($warnings, fn (array $a, array $b) =>
            strcmp((string) ($a['risk'] ?? ''), (string) ($b['risk'] ?? ''))
        );

        return $warnings;
    }

    private function recommendedNextAction(array $creator, array $relatedTasks = [], array $timeline = [], array $hardDisqualifiers = []): string
    {
        $action = $this->nextBestAction($creator, $relatedTasks, $timeline, $hardDisqualifiers);
        $title = trim((string) ($action['title'] ?? ''));
        $reason = trim((string) ($action['reason'] ?? ''));

        return trim($title . ($reason !== '' ? '. ' . $reason : ''));
    }

    private function nextBestAction(array $creator, array $relatedTasks = [], array $timeline = [], array $hardDisqualifiers = []): array
    {
        $openTasks = array_values(array_filter($relatedTasks, fn (array $task) => !in_array((string) ($task['status'] ?? ''), ['completed', 'skipped', 'archived'], true)));
        $state = (string) ($creator['lifecycleState'] ?? '');
        $hasEmail = trim((string) ($creator['email'] ?? '')) !== '';
        $score = (int) ($creator['valueScore'] ?? 0);
        $engagement = (float) ($creator['engagementRate'] ?? 0);
        $followers = (int) ($creator['followers'] ?? 0);
        $fitSignal = $this->profileFitSignal($followers, $engagement, $score);
        $latestOutreachAt = $this->latestTimelineTimestamp($timeline, $this->strictOutreachSentEventTypes());
        $latestReplyAt = $this->latestTimelineTimestamp($timeline, $this->strictReplyEventTypes());
        $supportingFacts = array_values(array_filter([
            $fitSignal,
            $latestOutreachAt !== '' ? 'Last outreach was ' . $this->agePhrase($latestOutreachAt) . '.' : null,
            $latestReplyAt !== '' ? 'Last reply was ' . $this->agePhrase($latestReplyAt) . '.' : null,
            count($openTasks) > 0 ? count($openTasks) . ' open workflow task' . (count($openTasks) === 1 ? '' : 's') . ' attached.' : null,
        ]));

        $candidates = [];
        $addCandidate = function (array $candidate) use (&$candidates, $supportingFacts) {
            $candidate['supportingFacts'] = array_values(array_slice(array_unique(array_filter(array_merge(
                $candidate['supportingFacts'] ?? [],
                $supportingFacts
            ))), 0, 4));
            $candidate['primaryCta'] = (string) ($candidate['primaryCta'] ?? 'Open Outreach');
            $candidate['route'] = (string) ($candidate['route'] ?? 'outreach');
            $candidate['priority'] = (string) ($candidate['priority'] ?? 'normal');
            $candidate['source'] = (string) ($candidate['source'] ?? 'system_inferred');
            $candidate['requiresUserConfirmation'] = (bool) ($candidate['requiresUserConfirmation'] ?? false);
            $candidate['score'] = (int) ($candidate['score'] ?? 0);
            $candidates[] = $candidate;
        };

        if ($this->hasDuplicateBlocker($creator, $hardDisqualifiers)) {
            $addCandidate([
                'actionKey' => 'resolve_duplicate_risk',
                'title' => 'Resolve duplicate risk',
                'reason' => 'This profile has a duplicate signal, so keep only the correct creator before any new outreach is sent.',
                'primaryCta' => 'Open duplicate review',
                'route' => 'duplicates',
                'priority' => 'urgent',
                'source' => 'system_blocker',
                'requiresUserConfirmation' => true,
                'supportingFacts' => array_slice($hardDisqualifiers, 0, 2),
                'score' => in_array($state, ['contacted', 'replied', 'negotiating', 'accepted', 'won'], true) ? 82 : 100,
            ]);
        }

        if ($latestReplyAt !== '' || $state === 'replied') {
            $age = $latestReplyAt !== '' ? $this->agePhrase($latestReplyAt) : 'recently';
            $addCandidate([
                'actionKey' => 'draft_reply',
                'title' => 'Draft the response',
                'reason' => "A creator reply was logged {$age}. Use Outreach to answer while the conversation is warm and move it toward terms or a clear yes/no.",
                'primaryCta' => 'Draft response',
                'route' => 'outreach_reply',
                'priority' => 'urgent',
                'source' => 'relationship_signal',
                'score' => 96,
            ]);
        }

        foreach ($openTasks as $task) {
            if (!$this->taskCanLeadNextAction($state, (string) ($task['type'] ?? ''), $latestOutreachAt, $latestReplyAt)) {
                continue;
            }

            $candidate = $this->taskNextActionCandidate($task, $hasEmail);
            if ($candidate !== null) {
                $addCandidate($candidate);
            }
        }

        if ($latestOutreachAt !== '') {
            $days = $this->daysSince($latestOutreachAt);
            $age = $this->agePhrase($latestOutreachAt);
            if ($days !== null && $days >= 3) {
                $addCandidate([
                    'actionKey' => 'send_follow_up',
                    'title' => 'Send a follow-up',
                    'reason' => "First outreach was sent {$age} and no reply is logged. Send the follow-up now, then record the outcome.",
                    'primaryCta' => 'Open Outreach',
                    'route' => 'outreach',
                    'priority' => 'high',
                    'source' => 'timing_rule',
                    'score' => 90,
                ]);
            } else {
                $addCandidate([
                    'actionKey' => 'check_conversation',
                    'title' => 'Check the conversation',
                    'reason' => "First outreach was sent {$age}. Log a reply if one exists; otherwise let the follow-up window mature before sending the next message.",
                    'primaryCta' => 'Open Outreach',
                    'route' => 'outreach',
                    'priority' => 'normal',
                    'source' => 'timing_rule',
                    'score' => 64,
                ]);
            }
        }

        match ($state) {
            'discovered', 'needs_review', 'enriched', 'approved_for_outreach', 'queued' => $addCandidate([
                'actionKey' => $hasEmail ? 'send_first_email' : 'send_first_dm',
                'title' => $hasEmail ? 'Send the first email' : 'Send the first DM',
                'reason' => 'No outreach is logged yet. Start the conversation from Outreach and let the result update the creator history.',
                'primaryCta' => 'Open Outreach',
                'route' => 'outreach',
                'priority' => $score >= 65 ? 'high' : 'normal',
                'source' => 'lifecycle_state',
                'score' => $score >= 65 ? 80 : 74,
            ]),
            'contacted' => $latestOutreachAt === '' ? $addCandidate([
                'actionKey' => 'verify_contact_status',
                'title' => 'Verify the contact status',
                'reason' => 'This creator is marked contacted but no sent outreach timestamp is attached. Open Outreach, confirm what was sent, then log the reply or follow-up.',
                'primaryCta' => 'Open Outreach',
                'route' => 'outreach',
                'priority' => 'high',
                'source' => 'data_gap',
                'score' => 78,
            ]) : null,
            'negotiating' => $addCandidate([
                'actionKey' => 'confirm_terms',
                'title' => 'Confirm the next commitment',
                'reason' => 'The creator is in negotiation. Confirm the rate, deliverable, posting date, or mark the conversation won/lost.',
                'primaryCta' => 'Open Outreach',
                'route' => 'outreach',
                'priority' => 'high',
                'source' => 'lifecycle_state',
                'score' => 88,
            ]),
            'accepted' => $addCandidate([
                'actionKey' => 'confirm_delivery',
                'title' => 'Confirm delivery details',
                'reason' => 'The creator is accepted. Confirm the deliverable and capture spend or expected outcome data so ROI stays useful.',
                'primaryCta' => 'Open Outreach',
                'route' => 'outreach',
                'priority' => 'normal',
                'source' => 'lifecycle_state',
                'score' => 76,
            ]),
            'won' => $addCandidate([
                'actionKey' => 'track_creator_roi',
                'title' => 'Track creator ROI',
                'reason' => 'This creator is marked won. Add spend and result data so the relationship history becomes a reusable decision signal.',
                'primaryCta' => 'Open insights',
                'route' => 'roi',
                'priority' => 'normal',
                'source' => 'lifecycle_state',
                'score' => 70,
            ]),
            'declined', 'lost' => $addCandidate([
                'actionKey' => 'archive_or_revisit',
                'title' => 'Archive or set a revisit note',
                'reason' => 'This creator is not moving forward. Archive them unless there is a specific future reason to revisit the relationship.',
                'primaryCta' => 'Review profile',
                'route' => 'none',
                'priority' => 'low',
                'source' => 'lifecycle_state',
                'requiresUserConfirmation' => true,
                'score' => 48,
            ]),
            'archived' => $addCandidate([
                'actionKey' => 'review_archived_profile',
                'title' => 'Review archived profile',
                'reason' => 'This creator is archived. Only reactivate them if a new campaign gives you a clear reason.',
                'primaryCta' => '',
                'route' => 'none',
                'priority' => 'low',
                'source' => 'lifecycle_state',
                'score' => 20,
            ]),
            default => $addCandidate([
                'actionKey' => $hasEmail ? 'send_first_email' : 'send_first_dm',
                'title' => $hasEmail ? 'Send the first email' : 'Send the first DM',
                'reason' => 'No outreach is logged yet. Start from Outreach and record the outcome.',
                'primaryCta' => 'Open Outreach',
                'route' => 'outreach',
                'priority' => 'normal',
                'source' => 'fallback_rule',
                'score' => 60,
            ]),
        };

        usort($candidates, fn (array $a, array $b) => ($b['score'] <=> $a['score']) ?: strcmp((string) ($a['actionKey'] ?? ''), (string) ($b['actionKey'] ?? '')));
        $winner = $candidates[0] ?? [
            'actionKey' => $hasEmail ? 'send_first_email' : 'send_first_dm',
            'title' => $hasEmail ? 'Send the first email' : 'Send the first DM',
            'reason' => 'No outreach is logged yet. Start from Outreach and record the outcome.',
            'primaryCta' => 'Open Outreach',
            'route' => 'outreach',
            'priority' => 'normal',
            'source' => 'fallback_rule',
            'requiresUserConfirmation' => false,
            'supportingFacts' => $supportingFacts,
            'score' => 1,
        ];

        unset($winner['score']);
        return $winner;
    }

    private function hasDuplicateBlocker(array $creator, array $hardDisqualifiers): bool
    {
        $state = (string) ($creator['lifecycleState'] ?? '');
        if ($state === 'duplicate_review_needed' || (string) ($creator['duplicateRisk'] ?? 'low') === 'high') {
            return true;
        }

        foreach ($hardDisqualifiers as $item) {
            $normalized = strtolower((string) $item);
            if (str_contains($normalized, 'duplicate') || str_contains($normalized, 'shared handle') || str_contains($normalized, 'shared email')) {
                return true;
            }
        }

        return false;
    }

    private function taskCanLeadNextAction(string $state, string $taskType, string $latestOutreachAt = '', string $latestReplyAt = ''): bool
    {
        $taskType = strtoupper(trim($taskType));
        $supportTasks = ['FOLLOW_REQUEST', 'COMMENT_ON_POST'];
        if (in_array($taskType, $supportTasks, true) && in_array($state, ['contacted', 'replied', 'negotiating', 'accepted', 'won', 'lost', 'archived'], true)) {
            return false;
        }

        if (in_array($taskType, ['DM_INVITE', 'EMAIL_SEND'], true) && $latestOutreachAt !== '') {
            return false;
        }

        if (in_array($taskType, ['DM_FOLLOWUP', 'CHECK_IN'], true) && $latestReplyAt !== '') {
            return false;
        }

        return in_array($taskType, [
            'DM_INVITE',
            'DM_FOLLOWUP',
            'EMAIL_SEND',
            'NEGOTIATE_TERMS',
            'CHECK_IN',
            'CONFIRM_ACCEPTED',
            'CONFIRM_POSTED',
            'FOLLOW_REQUEST',
            'COMMENT_ON_POST',
        ], true);
    }

    private function taskNextActionCandidate(array $task, bool $hasEmail): ?array
    {
        $type = strtoupper((string) ($task['type'] ?? ''));
        $due = trim((string) ($task['dueDate'] ?? ''));
        $dueFact = $due !== '' ? $this->duePhrase($due) : '';

        $base = [
            'source' => 'task_due',
            'requiresUserConfirmation' => false,
            'supportingFacts' => array_values(array_filter([$dueFact])),
        ];

        return match ($type) {
            'DM_INVITE' => array_merge($base, [
                'actionKey' => 'send_first_dm',
                'title' => 'Send the first DM',
                'reason' => 'The next open task is a DM invite. Send it from Outreach so the send is logged automatically.',
                'primaryCta' => 'Open Outreach',
                'route' => 'outreach',
                'priority' => 'high',
                'score' => 86,
            ]),
            'DM_FOLLOWUP' => array_merge($base, [
                'actionKey' => 'send_follow_up',
                'title' => 'Send the follow-up',
                'reason' => 'The next open task is a follow-up and no reply is logged. Send it from Outreach and record the outcome.',
                'primaryCta' => 'Open Outreach',
                'route' => 'outreach',
                'priority' => 'high',
                'score' => 92,
            ]),
            'EMAIL_SEND' => array_merge($base, [
                'actionKey' => 'send_first_email',
                'title' => 'Send the first email',
                'reason' => 'The next open task is an email send. Send it from Outreach so the message and creator history stay connected.',
                'primaryCta' => 'Open Outreach',
                'route' => 'outreach',
                'priority' => 'high',
                'score' => $hasEmail ? 88 : 72,
            ]),
            'NEGOTIATE_TERMS' => array_merge($base, [
                'actionKey' => 'confirm_terms',
                'title' => 'Confirm terms',
                'reason' => 'The next open task is negotiation. Ask for the rate, deliverable, posting date, or close the conversation.',
                'primaryCta' => 'Open Outreach',
                'route' => 'outreach',
                'priority' => 'high',
                'score' => 89,
            ]),
            'CHECK_IN' => array_merge($base, [
                'actionKey' => 'check_conversation',
                'title' => 'Check the conversation',
                'reason' => 'The next open task is a check-in. Record whether they replied, declined, or need another follow-up.',
                'primaryCta' => 'Open Outreach',
                'route' => 'outreach',
                'priority' => 'normal',
                'score' => 74,
            ]),
            'CONFIRM_ACCEPTED' => array_merge($base, [
                'actionKey' => 'confirm_delivery',
                'title' => 'Confirm acceptance',
                'reason' => 'The next open task is acceptance confirmation. Confirm the deliverable and schedule the follow-through.',
                'primaryCta' => 'Open Outreach',
                'route' => 'outreach',
                'priority' => 'normal',
                'score' => 82,
            ]),
            'CONFIRM_POSTED' => array_merge($base, [
                'actionKey' => 'confirm_posted',
                'title' => 'Confirm the post is live',
                'reason' => 'The next open task is post confirmation. Confirm the post, then update ROI if spend or outcome data exists.',
                'primaryCta' => 'Open Outreach',
                'route' => 'outreach',
                'priority' => 'normal',
                'score' => 80,
            ]),
            'FOLLOW_REQUEST' => array_merge($base, [
                'actionKey' => 'complete_follow_request',
                'title' => 'Complete the follow request',
                'reason' => 'This warm-up task is still open. Complete it only if you use follow requests before first outreach.',
                'primaryCta' => 'Open profile',
                'route' => 'external_profile',
                'priority' => 'low',
                'score' => 52,
            ]),
            'COMMENT_ON_POST' => array_merge($base, [
                'actionKey' => 'comment_on_post',
                'title' => 'Leave the planned comment',
                'reason' => 'This warm-up task is still open. Use it as support, then send the real outreach from Outreach.',
                'primaryCta' => 'Open profile',
                'route' => 'external_profile',
                'priority' => 'low',
                'score' => 50,
            ]),
            default => null,
        };
    }

    private function profileFitSignal(int $followers, float $engagement, int $score): string
    {
        $parts = [];
        if ($followers > 0) {
            $parts[] = number_format($followers) . ' followers';
        }
        if ($engagement > 0) {
            $parts[] = round($engagement, 1) . '% engagement';
        }
        if ($score > 0) {
            $parts[] = 'value score ' . $score;
        }

        return $parts === []
            ? 'Profile data is still thin.'
            : 'Profile signals: ' . implode(', ', $parts) . '.';
    }

    private function daysSince(string $value): ?int
    {
        try {
            return (int) floor(Carbon::parse($value)->diffInHours(now()) / 24);
        } catch (\Throwable) {
            return null;
        }
    }

    private function agePhrase(string $value): string
    {
        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return 'recently';
        }

        $hours = $date->diffInHours(now());
        if ($hours < 24) {
            return 'today';
        }

        $days = (int) floor($hours / 24);
        if ($days === 1) {
            return 'yesterday';
        }

        return $days . ' days ago';
    }

    private function duePhrase(string $value): string
    {
        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return 'Due ' . $value . '.';
        }

        if ($date->isPast()) {
            return 'This task is due now.';
        }

        return 'Due ' . $date->diffForHumans(['parts' => 1, 'join' => true]) . '.';
    }

    private function confidenceScore(array $creator, array $confidenceReasons, array $hardDisqualifiers, array $timeline): int
    {
        $score = 20;
        $score += min(45, count($confidenceReasons) * 12);
        if (($creator['valueScore'] ?? 0) >= 65) {
            $score += 15;
        } elseif (($creator['valueScore'] ?? 0) >= 45) {
            $score += 8;
        }
        if ($this->latestTimelineTimestamp($timeline, $this->strictOutreachSentEventTypes()) !== '') {
            $score += 10;
        }
        if ($this->latestTimelineTimestamp($timeline, $this->strictReplyEventTypes()) !== '') {
            $score += 10;
        }
        $score -= min(35, count($hardDisqualifiers) * 15);

        return (int) max(0, min(100, $score));
    }

    private function creatorOutreachEventQuery(int $projectId, CreatorProfile $profile, array $creator): Builder
    {
        $platform = strtolower((string) ($creator['platform'] ?? $profile->platform ?? ''));
        $handle = strtolower(ltrim((string) ($creator['handle'] ?? $profile->handle ?? ''), '@'));

        return OutreachEvent::query()
            ->where('project_id', $projectId)
            ->where(function (Builder $query) use ($profile, $platform, $handle) {
                $query->where('creator_profile_id', $profile->id)
                    ->orWhere(function (Builder $eventQuery) use ($platform, $handle) {
                        $eventQuery->whereRaw("LOWER(COALESCE(platform, '')) = ?", [$platform])
                            ->whereRaw("LOWER(REPLACE(COALESCE(handle, ''), '@', '')) = ?", [$handle]);
                    });
            });
    }

    private function latestTimelineTimestamp(array $timeline, array $eventTypes): string
    {
        $types = array_map('strtoupper', $eventTypes);

        foreach ($timeline as $item) {
            $eventType = strtoupper((string) ($item['type'] ?? ''));
            if (in_array($eventType, $types, true)) {
                return (string) ($item['timestamp'] ?? '');
            }
        }

        return '';
    }

    private function lastRealSignalLabel(array $creator, array $timeline): string
    {
        $replyAt = $this->latestTimelineTimestamp($timeline, $this->strictReplyEventTypes());
        if ($replyAt !== '') {
            return 'Creator replied at ' . $replyAt;
        }

        $outreachAt = $this->latestTimelineTimestamp($timeline, $this->strictOutreachSentEventTypes());
        if ($outreachAt !== '') {
            return 'Outreach sent at ' . $outreachAt;
        }

        $lastContentDate = trim((string) ($creator['lastContentDate'] ?? ''));
        return $lastContentDate !== '' ? $lastContentDate : 'No source post date captured';
    }

    private function creatorRoi(array $creator, array $timeline): array
    {
        $outreachCount = 0;
        $replyCount = 0;
        $dealCount = 0;

        foreach ($timeline as $item) {
            $eventType = strtoupper((string) ($item['type'] ?? ''));
            if (in_array($eventType, $this->strictOutreachSentEventTypes(), true)) {
                $outreachCount++;
            }
            if (in_array($eventType, $this->strictReplyEventTypes(), true)) {
                $replyCount++;
            }
            if (in_array($eventType, ['DEAL_WON', 'ACCEPTED'], true)) {
                $dealCount++;
            }
        }

        $manualSpend = 0.0;
        $manualDeals = 0;
        $projectId = trim((string) ($creator['projectId'] ?? ''));
        if ($projectId !== '') {
            try {
                $events = DB::table('roi_events')
                    ->where('project_id', $projectId)
                    ->whereRaw("LOWER(COALESCE(platform, '')) = ?", [strtolower((string) ($creator['platform'] ?? ''))])
                    ->whereRaw("LOWER(REPLACE(COALESCE(creator_handle, ''), '@', '')) = ?", [strtolower(ltrim((string) ($creator['handle'] ?? ''), '@'))])
                    ->get(['event_type', 'amount', 'metadata']);

                foreach ($events as $event) {
                    $type = (string) ($event->event_type ?? '');
                    $metadata = is_string($event->metadata ?? null) ? json_decode($event->metadata, true) : [];
                    $isEstimated = is_array($metadata) && ((bool) ($metadata['estimated'] ?? false) || (bool) ($metadata['demoSafeEstimate'] ?? false));
                    if (in_array($type, ['campaign_spend', 'campaign_spend_adjustment'], true) && !$isEstimated) {
                        $manualSpend += (float) ($event->amount ?? 0);
                    }
                    if ($type === 'deal_closed') {
                        $manualDeals++;
                    }
                }
            } catch (\Throwable) {
                $manualSpend = 0.0;
                $manualDeals = 0;
            }
        }

        $dealCount = max($dealCount, $manualDeals);

        return [
            'totalSpend' => round($manualSpend, 2),
            'outreachCount' => $outreachCount,
            'repliesReceived' => $replyCount,
            'dealsClosed' => $dealCount,
            'costPerReply' => $replyCount > 0 ? round($manualSpend / $replyCount, 2) : 0,
            'costPerDeal' => $dealCount > 0 ? round($manualSpend / $dealCount, 2) : 0,
        ];
    }

    private function normalizeEnrichmentStatus(array $row): string
    {
        $rawStatus = Str::upper(trim((string) ($row['Status'] ?? '')));
        $notes = (string) ($row['Notes'] ?? '');
        $sourceTag = Str::lower((string) ($this->extractTaggedValue($notes, 'source') ?? ''));

        if (
            in_array($rawStatus, ['FAILED', 'ENRICHMENT_FAILED', 'FAILED_ENRICHMENT'], true)
            || Str::contains(Str::lower($notes), ['enrichment_failed', 'enrichment failed'])
        ) {
            return 'failed';
        }

        $hasEnrichmentData =
            trim((string) ($row['Followers'] ?? '')) !== ''
            || trim((string) ($row['Engagement_Rate_%'] ?? '')) !== ''
            || trim((string) ($row['Contact_Email'] ?? '')) !== '';

        if ($hasEnrichmentData || in_array($sourceTag, ['ig_enriched', 'tiktok_enriched'], true)) {
            return 'enriched';
        }

        return 'pending';
    }

    private function extractTaggedValue(string $text, string $key): ?string
    {
        if (preg_match('/(?:^|[;|\s])' . preg_quote($key, '/') . '=([^;|]+)/', $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = preg_replace('/[^0-9\-]/', '', (string) $value);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return (int) $normalized;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', (string) $value);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    private function presentableValueScore(int|float|string|null $score, ?int $followers, ?float $engagementRate): int
    {
        $score = (float) ($score ?? 0);
        if ($score <= 0 || ($followers ?? 0) <= 0) {
            return (int) max(0, min(100, round($score)));
        }

        $engagementRate = (float) ($engagementRate ?? 0);
        $lift = 0;
        if ($score < 65) {
            $lift += $score >= 35 ? 10 : 8;
        }
        if ($engagementRate >= 2) {
            $lift += 5;
        }
        if (($followers ?? 0) >= 10000) {
            $lift += 4;
        }

        $cap = $engagementRate > 0 ? 82 : 58;

        return (int) max(0, min(100, min($cap, round($score + $lift))));
    }

    private function strictOutreachSentEventTypes(): array
    {
        return [
            'OUTREACH_SENT',
            'OUTREACH_SENT_CONFIRMED',
            'MESSAGE_SENT',
            'DM_SENT',
            'DM_SENT_CONFIRMED',
            'FOLLOWUP_SENT_CONFIRMED',
            'EMAIL_SENT',
            'EMAIL_SENT_CONFIRMED',
            'SENT',
        ];
    }

    private function strictReplyEventTypes(): array
    {
        return [
            'REPLY_RECEIVED',
            'REPLY',
            'CREATOR_REPLY',
            'CREATOR_REPLIED',
            'DM_REPLY_RECEIVED',
            'FOLLOWUP_REPLY_RECEIVED',
            'EMAIL_REPLY_RECEIVED',
            'ACCEPTED',
            'DEAL_WON',
        ];
    }


}
