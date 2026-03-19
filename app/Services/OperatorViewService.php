<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OperatorViewService
{
    public function __construct(
        private GoogleSheetsService $sheets,
        private InfluencerScoringService $scoring,
        private CreatorLifecycleService $lifecycle,
    ) {
    }

    public function build(string $sheetId): array
    {
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
        $creatorRows = $this->safeGetRows($sheetId, 'Creators_CRM');
        $creatorRow = collect($creatorRows)
            ->first(fn (array $row) => (int) ($row['_row_number'] ?? 0) === $rowNumber);

        if (!$creatorRow) {
            throw new \RuntimeException('Creator not found');
        }

        $creator = $this->normalizeCreatorCard($creatorRow);

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

        return [
            'creator' => $creator,
            'decisionSheet' => [
                'whoTheyAre' => trim(implode(' · ', array_filter([
                    $creator['fullName'] ?: $creator['handle'],
                    ucfirst($creator['platform']),
                    $creator['niche'] ?: null,
                ]))),
                'whyTheyFit' => array_values(array_filter([
                    ($creator['valueScore'] ?? 0) >= 70 ? 'High-value creator by current score' : null,
                    ($creator['engagementRate'] ?? 0) >= 2 ? 'Engagement rate is usable' : null,
                    $creator['email'] ? 'Direct contact route exists' : 'DM-first outreach only',
                    $creator['niche'] ? 'Niche present: ' . $creator['niche'] : 'Niche not classified yet',
                ])),
                'confidenceSummary' => [
                    'score' => min(100, count($confidenceReasons) * 25),
                    'label' => count($confidenceReasons) >= 3 ? 'usable' : 'fragile',
                    'reasons' => $confidenceReasons,
                ],
                'lastRealActivity' => $creator['lastContentDate'] ?: 'unknown',
                'lastOutreach' => $creator['lastContactDate'] ?: 'none',
                'duplicateRisk' => [
                    'level' => count($duplicates) > 0 ? ($duplicates[0]['risk'] ?? 'medium') : 'low',
                    'reasons' => count($duplicates) > 0
                        ? array_values(array_unique(array_filter(array_map(
                            fn (array $warning) => (string) ($warning['reason'] ?? ''),
                            $duplicates
                        ))))
                        : ['No duplicate warning currently surfaced'],
                    'relatedCreators' => count($duplicates) > 0
                        ? array_values(array_unique(array_filter(array_merge(...array_map(
                            fn (array $warning) => array_map(
                                fn (array $item) => $item['handle'] . ' (' . $item['platform'] . ')',
                                $warning['creators']
                            ),
                            $duplicates
                        )))))
                        : [],
                ],
                'recommendedNextAction' => $this->recommendedNextAction($creator),
                'hardDisqualifiers' => $hardDisqualifiers,
                'operatorNotes' => (string) ($creator['notes'] ?? ''),
                'timeline' => array_slice($timeline, 0, 20),
                'relatedTasks' => array_slice($relatedTasks, 0, 10),
            ],
        ];
    }

    private function safeGetRows(string $sheetId, string $sheetName): array
    {
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

    private function normalizeCreatorCard(array $row): array
    {
        $platform = Str::lower((string) ($row['Platform'] ?? 'instagram'));
        $enrichmentStatus = $this->normalizeEnrichmentStatus($row);
        $state = $this->lifecycle->normalizeState((string) ($row['Status'] ?? ''), $enrichmentStatus);
        $score = is_numeric((string) ($row['Value_Score'] ?? ''))
            ? (float) $row['Value_Score']
            : $this->scoring->score($row);

        $addedAt = $this->extractTaggedValue((string) ($row['Notes'] ?? ''), 'added_to_crm_at') ?? '';
        $lastContentDate = trim((string) ($row['Last_Content_Date'] ?? ''));
        $lastContentDate = str_starts_with($lastContentDate, '=') ? '' : $lastContentDate;

        return [
            'id' => 'crm:' . (int) ($row['_row_number'] ?? 0),
            'rowNumber' => (int) ($row['_row_number'] ?? 0),
            'platform' => $platform,
            'handle' => (string) ($row['Handle'] ?? ''),
            'fullName' => (string) ($row['Name'] ?? ''),
            'followers' => $this->toInt($row['Followers'] ?? null),
            'engagementRate' => $this->toFloat($row['Engagement_Rate_%'] ?? null),
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
                'messageText' => (string) ($row['Message_Draft'] ?? ''),
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

    private function recommendedNextAction(array $creator): string
    {
        return match ($creator['lifecycleState']) {
            'discovered' => 'Review basics and either reject or move to review',
            'needs_review' => 'Decide whether this is worth enrichment/outreach',
            'enriched' => 'Approve for outreach if the data is good enough',
            'duplicate_review_needed' => 'Resolve duplicate risk before touching outreach',
            'approved_for_outreach' => 'Queue the creator and open outreach',
            'queued' => 'Send first outreach now',
            'contacted' => 'Wait for reply or send follow-up',
            'replied' => 'Move into negotiation',
            'negotiating' => 'Close or archive the deal',
            'won', 'accepted' => 'Hand off / fulfill / keep notes clean',
            'lost', 'declined' => 'Archive unless there is a reason to revisit',
            default => 'Review manually',
        };
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
}
