<?php

namespace App\Services;

use App\Models\CreatorProfile;
use App\Models\MessageTemplate;
use App\Models\OutreachEvent;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MessagePerformanceService
{
    private array $recordsCache = [];

    private array $creatorTargetCache = [];

    private const TERMINAL_TASK_STATUSES = ['COMPLETED', 'DONE', 'SKIPPED', 'ARCHIVED'];

    private const OUTBOUND_TASK_TYPES = [
        'DM_INVITE',
        'DM_FOLLOWUP',
        'EMAIL_SEND',
        'NEGOTIATE_TERMS',
        'CHECK_IN',
        'CONFIRM_ACCEPTED',
        'CONFIRM_POSTED',
        'COMMENT_ON_POST',
    ];

    public function __construct(
        private ProjectResolverService $projects,
    ) {}

    public function summaryForSheet(string $sheetId, array $filters = []): array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if (! $project) {
            return $this->emptySummary($filters, 'Project not found for this workbook.');
        }

        return $this->summaryForProject((string) $project->id, $filters);
    }

    public function summaryForProject(string $projectId, array $filters = [], Collection|array|null $templates = null): array
    {
        $target = $this->normalizeTarget($filters, $projectId);
        $templateRows = $templates instanceof Collection
            ? $templates->values()
            : ($templates !== null ? collect($templates)->values() : $this->loadTemplates($projectId, $target));

        if ($templateRows->isEmpty()) {
            return $this->emptySummary($filters, 'No message templates found for this segment.', $target);
        }

        $records = $this->recordsForProject($projectId);

        $templateIds = $templateRows
            ->map(fn (MessageTemplate $template) => (string) $template->id)
            ->filter()
            ->values()
            ->all();

        $records = array_values(array_filter($records, function (array $record) use ($templateIds, $target) {
            if (! in_array((string) ($record['template_id'] ?? ''), $templateIds, true)) {
                return false;
            }

            return $this->recordFitsHardTarget($record, $target);
        }));

        $global = $this->aggregateRecords($records, null, 1.0);
        $globalBaseline = $global['successScore'] > 0 ? (float) $global['successScore'] : 45.0;

        $templateSummaries = [];
        foreach ($templateRows as $template) {
            $templateRecords = array_values(array_filter(
                $records,
                fn (array $record) => (string) ($record['template_id'] ?? '') === (string) $template->id
            ));

            $templateFit = $this->templateSegmentFit($template, $target);
            $recordFit = $this->segmentFitForRecords($templateRecords, $target);
            $segmentFit = round($templateFit * (0.55 + (0.45 * $recordFit)), 2);
            $summary = $this->aggregateRecords($templateRecords, $template, $segmentFit, $globalBaseline);
            $summary['reasons'] = $this->recommendationReasons($summary, $template, $target);
            $templateSummaries[] = $summary;
        }

        usort($templateSummaries, function (array $a, array $b) {
            return ($b['recommendationScore'] <=> $a['recommendationScore'])
                ?: ($b['successScore'] <=> $a['successScore'])
                ?: ($b['sampleSize'] <=> $a['sampleSize']);
        });

        $ranked = [];
        foreach (array_slice($templateSummaries, 0, max(1, (int) ($filters['limit'] ?? 50))) as $index => $summary) {
            $summary['rank'] = $index + 1;
            $ranked[] = $summary;
        }

        $bestForTarget = $this->bestTargetRecommendation($ranked);
        $bestOverall = $ranked[0] ?? null;

        return [
            'filters' => $filters,
            'target' => $target,
            'global' => $global,
            'templates' => $ranked,
            'recommendations' => [
                'bestForTarget' => $bestForTarget,
                'bestOverall' => $bestOverall,
                'bestFallback' => $bestForTarget ?: $bestOverall,
                'emptyReason' => $records === []
                    ? 'No completed message tasks or manual outreach events with templates yet.'
                    : null,
            ],
        ];
    }

    /**
     * Used by TaskQueueService when creating a new task. Returns null when the data is too thin.
     */
    public function recommendedTemplateIdForTask(
        string $projectId,
        array $templates,
        CreatorProfile $profile,
        string $targetPlatform,
        string $targetStage
    ): ?string {
        $profile->loadMissing('creator');

        $summary = $this->summaryForProject($projectId, [
            'platform' => $targetPlatform,
            'stage' => $targetStage,
            'niche' => $this->normalizeSegmentValue((string) ($profile->creator?->niche_category ?: '')),
            'followerBand' => $this->followerBand($profile->followers_count),
            'valueTier' => $this->valueTier($profile->value_score),
            'creatorProfileId' => (string) $profile->id,
            'limit' => 20,
        ], $templates);

        $candidate = $summary['recommendations']['bestForTarget']
            ?? $summary['recommendations']['bestOverall']
            ?? null;

        if (! $candidate) {
            return null;
        }

        $sampleSize = (int) ($candidate['sampleSize'] ?? 0);
        $confidence = (float) ($candidate['confidence'] ?? 0);
        $segmentFit = (float) ($candidate['segmentFit'] ?? 0);
        $recommendationScore = (float) ($candidate['recommendationScore'] ?? 0);

        if ($sampleSize < 2 || $confidence < 0.2 || $segmentFit < 0.55 || $recommendationScore < 35) {
            return null;
        }

        return (string) ($candidate['templateId'] ?? '') ?: null;
    }

    private function loadTemplates(string $projectId, array $target): Collection
    {
        $query = MessageTemplate::query()->where('project_id', $projectId);

        if (($target['platform'] ?? '') !== '') {
            $platform = (string) $target['platform'];
            $query->where(function ($q) use ($platform) {
                $q->whereRaw('LOWER(platform) = ?', [$platform]);
                if ($platform === 'email') {
                    $q->orWhereRaw('LOWER(platform) = ?', ['instagram']);
                }
            });
        }

        if (($target['stage'] ?? '') !== '') {
            $query->whereRaw('LOWER(stage) = ?', [(string) $target['stage']]);
        }

        if (($target['niche'] ?? '') !== '') {
            $niche = (string) $target['niche'];
            $query->where(function ($q) use ($niche) {
                $q->whereRaw("LOWER(COALESCE(niche, '')) = ?", [$niche])
                    ->orWhereNull('niche')
                    ->orWhere('niche', '');
            });
        }

        return $query->orderByDesc('created_at')->limit(500)->get();
    }

    private function recordsForProject(string $projectId): array
    {
        if (! array_key_exists($projectId, $this->recordsCache)) {
            $records = array_merge(
                $this->taskRecords($projectId),
                $this->manualEventRecords($projectId),
            );
            $this->recordsCache[$projectId] = $this->applyProfileOutcomeInference($records);
        }

        return $this->recordsCache[$projectId];
    }

    private function taskRecords(string $projectId): array
    {
        $tasks = Task::query()
            ->with(['messageTemplate', 'creatorProfile.creator'])
            ->where('project_id', $projectId)
            ->whereNotNull('message_template_id')
            ->whereNotNull('completed_at')
            ->whereIn('status', self::TERMINAL_TASK_STATUSES)
            ->orderByDesc('completed_at')
            ->limit(5000)
            ->get();

        $records = [];
        foreach ($tasks as $task) {
            if (! $task->messageTemplate) {
                continue;
            }

            $taskType = strtoupper((string) ($task->task_type ?: ''));
            if (! in_array($taskType, self::OUTBOUND_TASK_TYPES, true)) {
                continue;
            }

            $profile = $task->creatorProfile;
            $template = $task->messageTemplate;
            $records[] = [
                'source' => 'task',
                'task_id' => (string) $task->id,
                'template_id' => (string) $template->id,
                'angle_id' => (string) ($template->angle_id ?: ''),
                'platform' => $this->normalizePlatform($taskType === 'EMAIL_SEND' ? 'email' : (string) ($task->platform ?: $template->platform)),
                'stage' => $this->normalizeStage((string) ($template->stage ?: $this->stageFromTaskType($taskType))),
                'task_type' => $taskType,
                'status' => strtoupper((string) ($task->status ?: '')),
                'outcome' => $this->normalizeOutcome((string) ($task->completion_outcome ?: $task->skip_reason ?: '')),
                'event_type' => '',
                'at' => $task->completed_at,
                'creator_profile_id' => (string) ($task->creator_profile_id ?: ''),
                'responded_at' => $profile?->responded_at,
                'profile_state' => $this->normalizeOutcome((string) ($profile?->lifecycle_state ?: $profile?->status ?: '')),
                'accepted_flag' => (bool) ($profile?->accepted_flag ?? false),
                'segment' => $this->segmentForProfile($profile, $template),
                'inferred_response' => false,
                'inferred_positive' => false,
                'inferred_won' => false,
            ];
        }

        return $records;
    }

    private function manualEventRecords(string $projectId): array
    {
        $events = OutreachEvent::query()
            ->with(['messageTemplate', 'creatorProfile.creator'])
            ->where('project_id', $projectId)
            ->whereNotNull('message_template_id')
            ->whereNull('task_id')
            ->orderByDesc('sent_at')
            ->limit(2500)
            ->get();

        $records = [];
        foreach ($events as $event) {
            if (! $event->messageTemplate) {
                continue;
            }

            $template = $event->messageTemplate;
            $profile = $event->creatorProfile;
            $eventType = strtoupper((string) ($event->event_type ?: ''));

            $records[] = [
                'source' => 'event',
                'task_id' => null,
                'template_id' => (string) $template->id,
                'angle_id' => (string) ($template->angle_id ?: ''),
                'platform' => $this->normalizePlatform((string) ($event->platform ?: $template->platform)),
                'stage' => $this->normalizeStage((string) ($template->stage ?: $this->stageFromEventType($eventType))),
                'task_type' => '',
                'status' => strtoupper((string) ($event->status ?: '')),
                'outcome' => $this->normalizeOutcome((string) ($event->status ?: $event->notes ?: '')),
                'event_type' => $eventType,
                'at' => $event->sent_at,
                'creator_profile_id' => (string) ($event->creator_profile_id ?: ''),
                'responded_at' => $profile?->responded_at,
                'profile_state' => $this->normalizeOutcome((string) ($profile?->lifecycle_state ?: $profile?->status ?: '')),
                'accepted_flag' => (bool) ($profile?->accepted_flag ?? false),
                'segment' => $this->segmentForProfile($profile, $template),
                'inferred_response' => false,
                'inferred_positive' => false,
                'inferred_won' => false,
            ];
        }

        return $records;
    }

    private function applyProfileOutcomeInference(array $records): array
    {
        $byProfile = [];
        foreach ($records as $index => $record) {
            $profileId = (string) ($record['creator_profile_id'] ?? '');
            if ($profileId === '') {
                continue;
            }
            $byProfile[$profileId][] = $index;
        }

        foreach ($byProfile as $indexes) {
            usort($indexes, function (int $a, int $b) use ($records) {
                return $this->timestamp($records[$a]['at'] ?? null) <=> $this->timestamp($records[$b]['at'] ?? null);
            });

            $first = $records[$indexes[0]] ?? [];
            $respondedAt = $first['responded_at'] ?? null;
            if ($respondedAt instanceof CarbonInterface) {
                $responseIndex = null;
                foreach ($indexes as $index) {
                    $sentAt = $records[$index]['at'] ?? null;
                    if (! $sentAt instanceof CarbonInterface) {
                        continue;
                    }
                    if ($sentAt->lessThanOrEqualTo($respondedAt) && $sentAt->greaterThanOrEqualTo($respondedAt->copy()->subDays(45))) {
                        $responseIndex = $index;
                    }
                }
                if ($responseIndex !== null) {
                    $records[$responseIndex]['inferred_response'] = true;
                }
            }

            $profileState = (string) ($first['profile_state'] ?? '');
            $accepted = (bool) ($first['accepted_flag'] ?? false)
                || in_array($profileState, ['accepted', 'won', 'posted', 'live'], true);
            if ($accepted) {
                $latestIndex = end($indexes);
                if ($latestIndex !== false) {
                    $records[$latestIndex]['inferred_positive'] = true;
                    $records[$latestIndex]['inferred_won'] = in_array($profileState, ['won', 'posted', 'live'], true);
                }
            }
        }

        return $records;
    }

    private function aggregateRecords(array $records, ?MessageTemplate $template = null, float $segmentFit = 1.0, float $baseline = 45.0): array
    {
        $sent = 0;
        $responses = 0;
        $positive = 0;
        $accepted = 0;
        $won = 0;
        $negative = 0;
        $noResponse = 0;
        $pending = 0;
        $scoreTotal = 0;
        $lastUsedAt = null;
        $segments = [];

        foreach ($records as $record) {
            $classification = $this->classifyRecord($record);
            if (! $classification['sent']) {
                continue;
            }

            $sent++;
            $responses += $classification['response'] ? 1 : 0;
            $positive += $classification['positive'] ? 1 : 0;
            $accepted += $classification['accepted'] ? 1 : 0;
            $won += $classification['won'] ? 1 : 0;
            $negative += $classification['negative'] ? 1 : 0;
            $noResponse += $classification['noResponse'] ? 1 : 0;
            $pending += $classification['pending'] ? 1 : 0;
            $scoreTotal += $classification['score'];

            $recordAt = $record['at'] ?? null;
            if ($recordAt instanceof CarbonInterface && (! $lastUsedAt || $recordAt->greaterThan($lastUsedAt))) {
                $lastUsedAt = $recordAt;
            }

            $segment = $record['segment'] ?? [];
            foreach (['niche', 'followerBand', 'valueTier', 'country', 'language'] as $key) {
                $value = (string) ($segment[$key] ?? '');
                if ($value !== '') {
                    $segments[$key][$value] = ($segments[$key][$value] ?? 0) + 1;
                }
            }
        }

        $averageScore = $sent > 0 ? $scoreTotal / $sent : 0;
        $successScore = $sent > 0 ? round((($averageScore * $sent) + ($baseline * 5)) / ($sent + 5)) : 0;
        $confidence = $sent > 0 ? round(min(1, $sent / 10), 2) : 0.0;
        $recommendationScore = $sent > 0
            ? round(($successScore * (0.65 + (0.35 * $confidence)) * (0.65 + (0.35 * $segmentFit))) + ($segmentFit * 12))
            : round($segmentFit * 12);

        return [
            'templateId' => $template ? (string) $template->id : null,
            'routeId' => $template ? $this->routeId($template) : null,
            'angleId' => $template ? (string) ($template->angle_id ?: '') : null,
            'platform' => $template ? $this->normalizePlatform((string) ($template->platform ?: 'instagram')) : null,
            'stage' => $template ? $this->normalizeStage((string) ($template->stage ?: 'cold_invite')) : null,
            'niche' => $template ? (string) ($template->niche ?: '') : null,
            'sampleSize' => $sent,
            'sentCount' => $sent,
            'responseCount' => $responses,
            'positiveCount' => $positive,
            'acceptedCount' => $accepted,
            'wonCount' => $won,
            'negativeCount' => $negative,
            'noResponseCount' => $noResponse,
            'pendingOutcomeCount' => $pending,
            'replyRate' => $sent > 0 ? round(($responses / $sent) * 100, 1) : 0.0,
            'positiveRate' => $sent > 0 ? round(($positive / $sent) * 100, 1) : 0.0,
            'conversionRate' => $sent > 0 ? round(($accepted / $sent) * 100, 1) : 0.0,            'successScore' => (int) $successScore,
            'recommendationScore' => (int) $recommendationScore,
            'confidence' => $confidence,
            'confidenceLabel' => $this->confidenceLabel($confidence),
            'segmentFit' => round($segmentFit, 2),
            'lastUsedAt' => $lastUsedAt instanceof CarbonInterface ? $lastUsedAt->toIso8601String() : null,
            'topSegments' => $this->topSegments($segments),
        ];
    }

    private function classifyRecord(array $record): array
    {
        $status = strtoupper((string) ($record['status'] ?? ''));
        if ($status === 'SKIPPED') {
            return $this->classification(false, false, false, false, false, false, false, false, 0);
        }

        $outcome = (string) ($record['outcome'] ?? '');
        $eventType = strtoupper((string) ($record['event_type'] ?? ''));
        $sentAt = $record['at'] ?? null;

        $response = (bool) ($record['inferred_response'] ?? false)
            || Str::contains($eventType, 'REPLY')
            || in_array($outcome, ['creator_replied', 'replied_elsewhere', 'conversation_active_elsewhere', 'needs_reply', 'reply', 'replied'], true);

        // Do not directly count current profile state/accepted flags on every past send.
        // Those are current-state fields, not per-message outcomes. applyProfileOutcomeInference()
        // assigns the final creator outcome to the most plausible outbound record instead.
        $won = (bool) ($record['inferred_won'] ?? false)
            || in_array($outcome, ['won', 'posted', 'live'], true);

        $accepted = $won
            || (bool) ($record['inferred_positive'] ?? false)
            || in_array($outcome, ['accepted', 'approved', 'move_to_outreach'], true);

        $negative = in_array($outcome, ['declined', 'lost', 'not_a_fit', 'archive', 'archived', 'rejected', 'bounced'], true);

        $positive = $accepted
        || (! $negative && in_array($outcome, ['positive', 'interested', 'negotiate', 'proposal'], true));

        if ($positive || $accepted || $won) {
            $response = true;
        }

        $fresh = $sentAt instanceof CarbonInterface && $sentAt->greaterThan(now()->subDays(7));
        $pending = ! $response && ! $positive && ! $accepted && ! $won && ! $negative && $fresh;
        $noResponse = ! $response && ! $positive && ! $accepted && ! $won && ! $negative && ! $fresh;

        $score = 35;
        if ($won) {
            $score = 100;
        } elseif ($accepted) {
            $score = 90;
        } elseif ($negative) {
            $score = 5;
        } elseif ($positive && $response) {
            $score = 78;
        } elseif ($positive) {
            $score = 72;
        } elseif ($response) {
            $score = 65;
        } elseif ($noResponse) {
            $score = 15;
        }

        return $this->classification(true, $response, $positive, $accepted, $won, $negative, $noResponse, $pending, $score);
    }

    private function classification(bool $sent, bool $response, bool $positive, bool $accepted, bool $won, bool $negative, bool $noResponse, bool $pending, int $score): array
    {
        return compact('sent', 'response', 'positive', 'accepted', 'won', 'negative', 'noResponse', 'pending', 'score');
    }

    private function recordFitsHardTarget(array $record, array $target): bool
    {
        if (($target['platform'] ?? '') !== '') {
            $recordPlatform = (string) ($record['platform'] ?? '');
            $targetPlatform = (string) $target['platform'];
            $allowed = $targetPlatform === 'email' ? ['email', 'instagram'] : [$targetPlatform];
            if (! in_array($recordPlatform, $allowed, true)) {
                return false;
            }
        }

        if (($target['stage'] ?? '') !== '' && (string) ($record['stage'] ?? '') !== (string) $target['stage']) {
            return false;
        }

        if (($target['taskType'] ?? '') !== '' && (string) ($record['task_type'] ?? '') !== (string) $target['taskType']) {
            return false;
        }

        return true;
    }

    private function templateSegmentFit(MessageTemplate $template, array $target): float
    {
        $fit = 1.0;

        $targetPlatform = (string) ($target['platform'] ?? '');
        if ($targetPlatform !== '') {
            $templatePlatform = $this->normalizePlatform((string) ($template->platform ?: ''));
            if ($templatePlatform === $targetPlatform) {
                $fit *= 1.0;
            } elseif ($targetPlatform === 'email' && $templatePlatform === 'instagram') {
                $fit *= 0.7;
            } else {
                $fit *= 0.35;
            }
        }

        $targetStage = (string) ($target['stage'] ?? '');
        if ($targetStage !== '') {
            $templateStage = $this->normalizeStage((string) ($template->stage ?: 'cold_invite'));
            $fit *= $templateStage === $targetStage ? 1.0 : 0.45;
        }

        $targetNiche = (string) ($target['niche'] ?? '');
        if ($targetNiche !== '') {
            $templateNiche = $this->normalizeSegmentValue((string) ($template->niche ?: ''));
            if ($templateNiche === '') {
                $fit *= 0.75;
            } elseif ($templateNiche === $targetNiche || Str::contains($templateNiche, $targetNiche) || Str::contains($targetNiche, $templateNiche)) {
                $fit *= 1.0;
            } else {
                $fit *= 0.5;
            }
        }

        return max(0.0, min(1.0, $fit));
    }

    private function segmentFitForRecords(array $records, array $target): float
    {
        if ($records === []) {
            return 1.0;
        }

        $total = 0.0;
        $count = 0;
        foreach ($records as $record) {
            $total += $this->recordSegmentFit((array) ($record['segment'] ?? []), $target);
            $count++;
        }

        return $count > 0 ? max(0.0, min(1.0, $total / $count)) : 1.0;
    }

    private function recordSegmentFit(array $segment, array $target): float
    {
        $fit = 1.0;

        $targetNiche = (string) ($target['niche'] ?? '');
        if ($targetNiche !== '') {
            $recordNiche = (string) ($segment['niche'] ?? '');
            if ($recordNiche === '') {
                $fit *= 0.7;
            } elseif ($recordNiche === $targetNiche || Str::contains($recordNiche, $targetNiche) || Str::contains($targetNiche, $recordNiche)) {
                $fit *= 1.0;
            } else {
                $fit *= 0.45;
            }
        }

        foreach (['followerBand', 'valueTier'] as $key) {
            $targetValue = (string) ($target[$key] ?? '');
            if ($targetValue === '') {
                continue;
            }
            $recordValue = (string) ($segment[$key] ?? '');
            if ($recordValue === '') {
                $fit *= 0.75;
            } elseif ($recordValue === $targetValue) {
                $fit *= 1.0;
            } else {
                $fit *= 0.55;
            }
        }

        return max(0.0, min(1.0, $fit));
    }

    private function bestTargetRecommendation(array $summaries): ?array
    {
        $eligible = array_values(array_filter($summaries, function (array $summary) {
            return (int) ($summary['sampleSize'] ?? 0) >= 2
                && (float) ($summary['segmentFit'] ?? 0) >= 0.55;
        }));

        return $eligible[0] ?? null;
    }

    private function recommendationReasons(array $summary, MessageTemplate $template, array $target): array
    {
        $reasons = [];
        $sampleSize = (int) ($summary['sampleSize'] ?? 0);

        if ($sampleSize === 0) {
            $reasons[] = 'No historical outcomes yet; only segment fit is available.';
        } else {
            $reasons[] = sprintf('%d completed send%s tracked.', $sampleSize, $sampleSize === 1 ? '' : 's');
            if (($summary['replyRate'] ?? 0) > 0) {
                $reasons[] = sprintf('%s%% reply rate.', $summary['replyRate']);
            }
            if (($summary['positiveCount'] ?? 0) > 0) {
                $reasons[] = sprintf('%d positive outcome%s.', $summary['positiveCount'], (int) $summary['positiveCount'] === 1 ? '' : 's');
            }
        }

        if (($target['platform'] ?? '') !== '' && $this->normalizePlatform((string) $template->platform) === (string) $target['platform']) {
            $reasons[] = 'Platform match.';
        }
        if (($target['stage'] ?? '') !== '' && $this->normalizeStage((string) $template->stage) === (string) $target['stage']) {
            $reasons[] = 'Stage match.';
        }
        if (($target['niche'] ?? '') !== '') {
            $templateNiche = $this->normalizeSegmentValue((string) ($template->niche ?: ''));
            $reasons[] = $templateNiche === (string) $target['niche']
                ? 'Niche match.'
                : 'Niche fallback.';
        }

        return array_values(array_unique($reasons));
    }

    private function segmentForProfile(?CreatorProfile $profile, MessageTemplate $template): array
    {
        $profile?->loadMissing('creator');

        return [
            'niche' => $this->normalizeSegmentValue((string) ($profile?->creator?->niche_category ?: $template->niche ?: '')),
            'followerBand' => $this->followerBand($profile?->followers_count),
            'valueTier' => $this->valueTier($profile?->value_score),
            'country' => $this->normalizeSegmentValue((string) ($profile?->creator?->country ?: '')),
            'language' => $this->normalizeSegmentValue((string) ($profile?->creator?->primary_language ?: '')),
        ];
    }

    private function normalizeTarget(array $filters, ?string $projectId = null): array
    {
        $target = [
            'platform' => $this->normalizePlatform((string) ($filters['platform'] ?? '')),
            'stage' => $this->normalizeStage((string) ($filters['stage'] ?? '')),
            'taskType' => strtoupper(trim((string) ($filters['taskType'] ?? ''))),
            'niche' => $this->normalizeSegmentValue((string) ($filters['niche'] ?? '')),
            'followerBand' => $this->normalizeSegmentValue((string) ($filters['followerBand'] ?? '')),
            'valueTier' => $this->normalizeSegmentValue((string) ($filters['valueTier'] ?? '')),
            'creatorProfileId' => trim((string) ($filters['creatorProfileId'] ?? '')),
            'targetSource' => '',
        ];

        if ($projectId && $target['creatorProfileId'] !== '') {
            $profileTarget = $this->targetFromCreatorProfile($projectId, $target['creatorProfileId']);
            if ($profileTarget) {
                foreach (['platform', 'niche', 'followerBand', 'valueTier'] as $key) {
                    if ($target[$key] === '' && ($profileTarget[$key] ?? '') !== '') {
                        $target[$key] = $profileTarget[$key];
                    }
                }
                $target['targetSource'] = 'creator_profile';
            }
        }

        return $target;
    }

    private function targetFromCreatorProfile(string $projectId, string $creatorProfileId): ?array
    {
        $cacheKey = $projectId.':'.$creatorProfileId;
        if (array_key_exists($cacheKey, $this->creatorTargetCache)) {
            return $this->creatorTargetCache[$cacheKey];
        }

        $profile = CreatorProfile::query()
            ->with('creator')
            ->where('project_id', $projectId)
            ->where('id', $creatorProfileId)
            ->first();

        if (! $profile) {
            return $this->creatorTargetCache[$cacheKey] = null;
        }

        return $this->creatorTargetCache[$cacheKey] = [
            'platform' => $this->normalizePlatform((string) ($profile->platform ?: '')),
            'niche' => $this->normalizeSegmentValue((string) ($profile->creator?->niche_category ?: '')),
            'followerBand' => $this->followerBand($profile->followers_count),
            'valueTier' => $this->valueTier($profile->value_score),
        ];
    }

    private function emptySummary(array $filters, ?string $reason = null, ?array $target = null): array
    {
        return [
            'filters' => $filters,
            'target' => $target ?: $this->normalizeTarget($filters),
            'global' => $this->aggregateRecords([]),
            'templates' => [],
            'recommendations' => [
                'bestForTarget' => null,
                'bestOverall' => null,
                'bestFallback' => null,
                'emptyReason' => $reason,
            ],
        ];
    }

    private function routeId(MessageTemplate $template): string
    {
        $metadata = is_array($template->metadata) ? $template->metadata : [];
        $rowNumber = (int) ($metadata['source_row_number'] ?? 0);

        return $rowNumber > 1 ? 'msg:'.$rowNumber : 'msgdb:'.$template->id;
    }

    private function normalizePlatform(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['instagram', 'tiktok', 'email'], true) ? $value : '';
    }

    private function normalizeStage(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['cold_invite', 'after_accept', 'follow_up', 'negotiation', 'check_in', 'post_confirmation'], true)
            ? $value
            : '';
    }

    private function normalizeOutcome(string $value): string
    {
        return Str::of($value)->lower()->replace([' ', '-'], '_')->trim()->toString();
    }

    private function normalizeSegmentValue(string $value): string
    {
        return Str::of($value)->lower()->replace(['-', '/', ','], ' ')->squish()->toString();
    }

    private function stageFromTaskType(string $taskType): string
    {
        return match (strtoupper($taskType)) {
            'DM_FOLLOWUP' => 'follow_up',
            'NEGOTIATE_TERMS' => 'negotiation',
            'CHECK_IN' => 'check_in',
            'CONFIRM_ACCEPTED' => 'after_accept',
            'CONFIRM_POSTED' => 'post_confirmation',
            default => 'cold_invite',
        };
    }

    private function stageFromEventType(string $eventType): string
    {
        return match (true) {
            Str::contains($eventType, 'FOLLOWUP') => 'follow_up',
            Str::contains($eventType, 'TERMS') => 'negotiation',
            Str::contains($eventType, 'POST') => 'post_confirmation',
            default => 'cold_invite',
        };
    }

    private function followerBand(mixed $followers): string
    {
        $count = (int) ($followers ?? 0);

        return match (true) {
            $count <= 0 => '',
            $count < 1000 => 'under_1k',
            $count < 10000 => '1k_10k',
            $count < 50000 => '10k_50k',
            $count < 250000 => '50k_250k',
            default => '250k_plus',
        };
    }

    private function valueTier(mixed $score): string
    {
        $value = (int) ($score ?? 0);

        return match (true) {
            $value >= 75 => 'high',
            $value >= 45 => 'medium',
            $value > 0 => 'low',
            default => '',
        };
    }

    private function confidenceLabel(float $confidence): string
    {
        return match (true) {
            $confidence >= 0.7 => 'high',
            $confidence >= 0.3 => 'medium',
            $confidence > 0 => 'low',
            default => 'none',
        };
    }

    private function topSegments(array $segments): array
    {
        $result = [];
        foreach ($segments as $key => $counts) {
            arsort($counts);
            $result[$key] = array_slice($counts, 0, 3, true);
        }

        return $result;
    }

    private function timestamp(mixed $value): int
    {
        return $value instanceof CarbonInterface ? $value->getTimestamp() : 0;
    }
}
