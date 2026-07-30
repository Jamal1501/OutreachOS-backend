<?php

namespace App\Services;

use App\Models\CreatorProfile;
use App\Models\DiscoveryItem;
use App\Models\OutreachEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsSummaryService
{
    public function __construct(
        private ProjectResolverService $projects,
        private WorkspaceBillingService $billing,
    ) {
    }

    public function summary(string $sheetId, string $workspaceId = '', string $range = '30d'): array
    {
        $range = in_array($range, ['7d', '30d', 'all'], true) ? $range : '30d';
        $rangeStart = $this->rangeStart($range);
        $rangeEnd = CarbonImmutable::now();
        $project = $this->projects->findByWorkbookId($sheetId);

        $billingUsage = $workspaceId !== ''
            ? $this->billing->customerUsageEstimate($workspaceId, $rangeStart, $range === 'all' ? null : $rangeEnd)
            : [
                'consumedScrapeCredits' => 0,
                'consumedAiCredits' => 0,
                'estimatedCreditSpendUsd' => 0,
                'estimatedOutreachInvestmentUsd' => 0,
                'customerCreditValue' => null,
            ];

        $base = $this->emptySummary($range, $rangeStart, $rangeEnd, $billingUsage);

        if (!$project) {
            return $base;
        }

        $reconciliation = $this->reconcileProjectLifecycle($project->id);
        $projectId = (int) $project->id;

        $sendQuery = OutreachEvent::query()
            ->where('project_id', $projectId)
            ->whereIn(DB::raw('UPPER(event_type)'), $this->strictOutreachSentEventTypes());
        $this->applyEventRange($sendQuery, $rangeStart, $range === 'all' ? null : $rangeEnd);

        $replyQuery = OutreachEvent::query()
            ->where('project_id', $projectId)
            ->whereIn(DB::raw('UPPER(event_type)'), $this->strictReplyEventTypes());
        $this->applyEventRange($replyQuery, $rangeStart, $range === 'all' ? null : $rangeEnd);

        $messagesSent = (int) (clone $sendQuery)->count();
        $creatorsContacted = $this->distinctCreatorCount(clone $sendQuery);
        $repliesReceived = (int) (clone $replyQuery)->count();
        $creatorsReplied = $this->distinctCreatorCount(clone $replyQuery);

        $lifecycleCounts = $this->lifecycleCounts($projectId);
        $discoveredCount = $this->discoveredCount($projectId);
        $creatorsEnriched = CreatorProfile::query()->where('project_id', $projectId)->count();
        $readyForOutreach = $this->readyForOutreachCount($projectId);
        $tasksDueToday = $this->tasksDueTodayCount($projectId);
        $manual = $this->manualRoiSummary($project, $workspaceId, $rangeStart, $range === 'all' ? null : $rangeEnd);

        $estimatedCreditSpendUsd = round((float) ($billingUsage['estimatedCreditSpendUsd'] ?? 0), 2);
        $estimatedOutreachInvestmentUsd = round($estimatedCreditSpendUsd + (float) $manual['manualCampaignSpendUsd'], 2);
        $replyRate = $messagesSent > 0 ? round(($repliesReceived / $messagesSent) * 100, 1) : 0.0;
        $dealRate = $messagesSent > 0 ? round(((int) $manual['dealsClosed'] / $messagesSent) * 100, 1) : 0.0;

        $performance = [
            'messagesSent' => $messagesSent,
            'outreachSent' => $messagesSent,
            'creatorsContacted' => $creatorsContacted,
            'repliesReceived' => $repliesReceived,
            'creatorsReplied' => $creatorsReplied,
            'dealsClosed' => (int) $manual['dealsClosed'],
            'replyRate' => $replyRate,
            'dealRate' => $dealRate,
        ];

        $economics = [
            'estimatedCreditSpendUsd' => $estimatedCreditSpendUsd,
            'manualCampaignSpendUsd' => round((float) $manual['manualCampaignSpendUsd'], 2),
            'estimatedOutreachInvestmentUsd' => $estimatedOutreachInvestmentUsd,
            'manualEventCount' => (int) $manual['manualEventCount'],
            'customerCreditValue' => $billingUsage['customerCreditValue'] ?? null,
        ];

        $usage = [
            'scrapeCreditsUsed' => (int) ($billingUsage['consumedScrapeCredits'] ?? 0),
            'aiCreditsUsed' => (int) ($billingUsage['consumedAiCredits'] ?? 0),
        ];

        $metrics = [
            'creatorsDiscovered' => $discoveredCount,
            'creatorsEnriched' => $creatorsEnriched,
            'readyForOutreach' => $readyForOutreach,
            'tasksDueToday' => $tasksDueToday,
            'outreachSent' => $messagesSent,
            'messagesSent' => $messagesSent,
            'creatorsContacted' => $creatorsContacted,
            'repliesReceived' => $repliesReceived,
            'creatorsReplied' => $creatorsReplied,
            'dealsClosed' => (int) $manual['dealsClosed'],
            'replyRate' => $replyRate,
            'scrapeSpend' => $estimatedOutreachInvestmentUsd,
            'estimatedOutreachInvestment' => $estimatedOutreachInvestmentUsd,
            'estimatedCreditSpendUsd' => $estimatedCreditSpendUsd,
            'manualCampaignSpendUsd' => round((float) $manual['manualCampaignSpendUsd'], 2),
            'scrapeCreditsUsed' => $usage['scrapeCreditsUsed'],
            'aiCreditsUsed' => $usage['aiCreditsUsed'],
            'customerCreditValue' => $billingUsage['customerCreditValue'] ?? null,
        ];

        return array_merge($base, [
            'source' => 'analytics_summary_service',
            'project' => [
                'id' => $projectId,
                'workspaceId' => $project->workspace_id,
                'workbookId' => $project->workbook_id,
            ],
            'metrics' => $metrics,
            'performance' => $performance,
            'lifecycle' => [
                'counts' => $lifecycleCounts,
                'totalCreators' => array_sum($lifecycleCounts),
                'explanation' => 'Lifecycle counts show where creators are right now. They are not historical send/reply totals.',
            ],
            'economics' => $economics,
            'usage' => $usage,
            'quality' => array_merge($base['quality'], [
                'unmatchedOutreachEvents' => $this->unmatchedOutreachCount($projectId),
                'reconciliation' => $reconciliation,
            ]),
            'previousPeriod' => $this->previousPeriodComparison($project, $workspaceId, $range, $rangeStart, $rangeEnd, $performance),
        ]);
    }

    public function reconcileProjectLifecycle(int $projectId): array
    {
        $linkedEvents = 0;
        $profilesUpdated = 0;

        OutreachEvent::query()
            ->where('project_id', $projectId)
            ->whereNull('creator_profile_id')
            ->where(function ($query) {
                $query->whereNotNull('platform')->orWhereNotNull('handle');
            })
            ->orderBy('created_at')
            ->chunkById(200, function ($events) use ($projectId, &$linkedEvents) {
                foreach ($events as $event) {
                    $profile = $this->findProfileForEvent($projectId, $event);
                    if (!$profile) {
                        continue;
                    }

                    $event->creator_profile_id = $profile->id;
                    $metadata = (array) ($event->metadata ?? []);
                    $metadata['creator_profile_linked_by'] = 'analytics_reconciliation';
                    $metadata['creator_profile_linked_at'] = now()->toIso8601String();
                    $event->metadata = $metadata;
                    $event->save();
                    $linkedEvents++;
                }
            });

        $events = OutreachEvent::query()
            ->where('project_id', $projectId)
            ->whereIn(DB::raw('UPPER(event_type)'), array_merge($this->strictOutreachSentEventTypes(), $this->strictReplyEventTypes()))
            ->where(function ($query) {
                $query->whereNotNull('creator_profile_id')
                    ->orWhere(function ($fallback) {
                        $fallback->whereNotNull('platform')->whereNotNull('handle');
                    });
            })
            ->orderByRaw('COALESCE(sent_at, created_at) asc')
            ->get();

        foreach ($events as $event) {
            $profile = $this->findProfileForEvent($projectId, $event);
            if (!$profile) {
                continue;
            }

            $before = $profile->getAttributes();
            $this->applyEventToProfile($profile, $event);
            if ($profile->getAttributes() !== $before) {
                $profilesUpdated++;
            }
        }

        return [
            'linkedEvents' => $linkedEvents,
            'profilesUpdated' => $profilesUpdated,
        ];
    }

    public function strictOutreachSentEventTypes(): array
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

    public function strictReplyEventTypes(): array
    {
        return [
            'REPLY_RECEIVED',
            'REPLY',
            'CREATOR_REPLIED',
            'DM_REPLY_RECEIVED',
            'FOLLOWUP_REPLY_RECEIVED',
            'EMAIL_REPLY_RECEIVED',
            'ACCEPTED',
            'DEAL_WON',
        ];
    }

    private function emptySummary(string $range, ?CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd, array $billingUsage): array
    {
        $estimatedCreditSpendUsd = round((float) ($billingUsage['estimatedCreditSpendUsd'] ?? 0), 2);

        return [
            'source' => 'analytics_summary_service',
            'range' => [
                'key' => $range,
                'label' => $range === 'all' ? 'all time' : 'last ' . ($range === '7d' ? '7' : '30') . ' days',
                'start' => $rangeStart?->toIso8601String(),
                'end' => $rangeEnd->toIso8601String(),
            ],
            'definitions' => [
                'messagesSent' => 'Confirmed outbound send events in the selected range.',
                'creatorsContacted' => 'Unique creators with at least one confirmed outbound send event in the selected range.',
                'repliesReceived' => 'Confirmed reply events in the selected range.',
                'lifecycleCounts' => 'Current creator workflow stages, not historical totals.',
                'estimatedOutreachInvestment' => 'Estimated from workflow capacity used plus manually tracked campaign spend.',
            ],
            'metrics' => [
                'creatorsDiscovered' => 0,
                'creatorsEnriched' => 0,
                'readyForOutreach' => 0,
                'tasksDueToday' => 0,
                'outreachSent' => 0,
                'messagesSent' => 0,
                'creatorsContacted' => 0,
                'repliesReceived' => 0,
                'creatorsReplied' => 0,
                'dealsClosed' => 0,
                'replyRate' => 0,
                'scrapeSpend' => $estimatedCreditSpendUsd,
                'estimatedOutreachInvestment' => $estimatedCreditSpendUsd,
                'estimatedCreditSpendUsd' => $estimatedCreditSpendUsd,
                'manualCampaignSpendUsd' => 0,
                'scrapeCreditsUsed' => (int) ($billingUsage['consumedScrapeCredits'] ?? 0),
                'aiCreditsUsed' => (int) ($billingUsage['consumedAiCredits'] ?? 0),
                'customerCreditValue' => $billingUsage['customerCreditValue'] ?? null,
            ],
            'performance' => [
                'messagesSent' => 0,
                'outreachSent' => 0,
                'creatorsContacted' => 0,
                'repliesReceived' => 0,
                'creatorsReplied' => 0,
                'dealsClosed' => 0,
                'replyRate' => 0,
                'dealRate' => 0,
            ],
            'lifecycle' => [
                'counts' => [],
                'totalCreators' => 0,
                'explanation' => 'Lifecycle counts show where creators are right now. They are not historical send/reply totals.',
            ],
            'economics' => [
                'estimatedCreditSpendUsd' => $estimatedCreditSpendUsd,
                'manualCampaignSpendUsd' => 0,
                'estimatedOutreachInvestmentUsd' => $estimatedCreditSpendUsd,
                'manualEventCount' => 0,
                'customerCreditValue' => $billingUsage['customerCreditValue'] ?? null,
            ],
            'usage' => [
                'scrapeCreditsUsed' => (int) ($billingUsage['consumedScrapeCredits'] ?? 0),
                'aiCreditsUsed' => (int) ($billingUsage['consumedAiCredits'] ?? 0),
            ],
            'quality' => [
                'unmatchedOutreachEvents' => 0,
                'reconciliation' => [
                    'linkedEvents' => 0,
                    'profilesUpdated' => 0,
                ],
            ],
        ];
    }

    private function rangeStart(string $range): ?CarbonImmutable
    {
        return match ($range) {
            '7d' => CarbonImmutable::now()->subDays(7),
            '30d' => CarbonImmutable::now()->subDays(30),
            default => null,
        };
    }

    private function previousPeriodComparison(Project $project, string $workspaceId, string $range, ?CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd, array $current): ?array
    {
        $days = match ($range) {
            '7d' => 7,
            '30d' => 30,
            default => null,
        };

        if ($days === null || $rangeStart === null) {
            return null;
        }

        $projectId = (int) $project->id;
        $previousEnd = $rangeStart;
        $previousStart = $rangeStart->subDays($days);

        $sendQuery = OutreachEvent::query()
            ->where('project_id', $projectId)
            ->whereIn(DB::raw('UPPER(event_type)'), $this->strictOutreachSentEventTypes());
        $this->applyEventRange($sendQuery, $previousStart, $previousEnd);

        $replyQuery = OutreachEvent::query()
            ->where('project_id', $projectId)
            ->whereIn(DB::raw('UPPER(event_type)'), $this->strictReplyEventTypes());
        $this->applyEventRange($replyQuery, $previousStart, $previousEnd);

        $messagesSent = (int) (clone $sendQuery)->count();
        $repliesReceived = (int) (clone $replyQuery)->count();
        $manual = $this->manualRoiSummary($project, $workspaceId, $previousStart, $previousEnd);
        $previous = [
            'messagesSent' => $messagesSent,
            'repliesReceived' => $repliesReceived,
            'dealsClosed' => (int) $manual['dealsClosed'],
            'replyRate' => $messagesSent > 0 ? round(($repliesReceived / $messagesSent) * 100, 1) : 0.0,
        ];

        return [
            'range' => [
                'label' => 'previous_' . $range,
                'start' => $previousStart->toIso8601String(),
                'end' => $previousEnd->toIso8601String(),
            ],
            'performance' => $previous,
            'delta' => [
                'messagesSent' => (int) ($current['messagesSent'] ?? 0) - $previous['messagesSent'],
                'repliesReceived' => (int) ($current['repliesReceived'] ?? 0) - $previous['repliesReceived'],
                'dealsClosed' => (int) ($current['dealsClosed'] ?? 0) - $previous['dealsClosed'],
                'replyRate' => round((float) ($current['replyRate'] ?? 0) - (float) $previous['replyRate'], 1),
            ],
        ];
    }

    private function applyEventRange($query, ?CarbonImmutable $from, ?CarbonImmutable $to = null): void
    {
        if ($from) {
            $query->where(function ($nested) use ($from) {
                $nested->where('sent_at', '>=', $from)
                    ->orWhere(function ($fallback) use ($from) {
                        $fallback->whereNull('sent_at')->where('created_at', '>=', $from);
                    });
            });
        }

        if ($to) {
            $query->where(function ($nested) use ($to) {
                $nested->where('sent_at', '<', $to)
                    ->orWhere(function ($fallback) use ($to) {
                        $fallback->whereNull('sent_at')->where('created_at', '<', $to);
                    });
            });
        }
    }

    private function distinctCreatorCount($query): int
    {
        return (int) ($query
            ->selectRaw("COUNT(DISTINCT COALESCE(creator_profile_id::text, LOWER(COALESCE(platform, '')) || ':' || LOWER(TRIM(LEADING '@' FROM COALESCE(handle, ''))))) as aggregate")
            ->value('aggregate') ?? 0);
    }

    private function lifecycleCounts(int $projectId): array
    {
        return CreatorProfile::query()
            ->where('project_id', $projectId)
            ->selectRaw("COALESCE(NULLIF(lifecycle_state, ''), 'discovered') as state, COUNT(*) as aggregate_count")
            ->groupByRaw("COALESCE(NULLIF(lifecycle_state, ''), 'discovered')")
            ->pluck('aggregate_count', 'state')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    private function discoveredCount(int $projectId): int
    {
        return (int) (DiscoveryItem::query()
            ->where('project_id', $projectId)
            ->selectRaw("COUNT(DISTINCT COALESCE(NULLIF(duplicate_key, ''), NULLIF(handle, ''), NULLIF(username, ''), NULLIF(post_url, ''), id::text)) as aggregate")
            ->value('aggregate') ?? 0);
    }

    private function readyForOutreachCount(int $projectId): int
    {
        return CreatorProfile::query()
            ->where('project_id', $projectId)
            ->where(function ($query) {
                $query->whereIn('lifecycle_state', ['approved_for_outreach', 'queued'])
                    ->orWhere(function ($nested) {
                        $nested->where('lifecycle_state', 'enriched')->where('value_score', '>=', 55);
                    })
                    ->orWhere(function ($nested) {
                        $nested->whereNull('lifecycle_state')->whereIn('status', ['APPROVED_FOR_OUTREACH', 'QUEUED']);
                    });
            })
            ->count();
    }

    private function tasksDueTodayCount(int $projectId): int
    {
        return Task::query()
            ->where('project_id', $projectId)
            ->whereDate('due_at', now()->toDateString())
            ->whereNotIn(DB::raw("UPPER(COALESCE(status, 'PENDING'))"), ['DONE', 'COMPLETED', 'SKIPPED'])
            ->count();
    }

    private function unmatchedOutreachCount(int $projectId): int
    {
        return OutreachEvent::query()
            ->where('project_id', $projectId)
            ->whereNull('creator_profile_id')
            ->whereIn(DB::raw('UPPER(event_type)'), array_merge($this->strictOutreachSentEventTypes(), $this->strictReplyEventTypes()))
            ->count();
    }

    private function manualRoiSummary(Project $project, string $workspaceId, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $workspaceKeys = array_values(array_filter(array_unique([
            $workspaceId,
            (string) ($project->workspace_id ?? ''),
            (string) ($project->id ?? ''),
            (string) ($project->workbook_id ?? ''),
            $this->workspaceSlug((string) ($project->workspace_id ?: $workspaceId)),
        ])));

        $query = DB::table('roi_events');
        $query->where(function ($q) use ($workspaceKeys) {
            foreach ($workspaceKeys as $key) {
                $q->orWhere('project_id', $key)->orWhere('workspace_id', $key);
            }
        });

        if ($from) {
            $query->where('event_date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->where('event_date', '<=', $to->toDateString());
        }

        try {
            $events = $query->get(['event_type', 'amount', 'metadata']);
        } catch (\Throwable) {
            return [
                'manualCampaignSpendUsd' => 0.0,
                'dealsClosed' => 0,
                'manualEventCount' => 0,
            ];
        }

        $manualSpend = 0.0;
        $dealsClosed = 0;
        $manualCount = 0;

        foreach ($events as $event) {
            $type = (string) ($event->event_type ?? '');
            $metadata = $this->decodeMetadata($event->metadata ?? null);
            $isEstimated = (bool) ($metadata['estimated'] ?? false) || (bool) ($metadata['demoSafeEstimate'] ?? false);
            $isManual = (bool) ($metadata['manual'] ?? false) || (bool) ($metadata['fallback'] ?? false);

            if (in_array($type, ['campaign_spend', 'campaign_spend_adjustment', 'scrape_spend'], true) && !$isEstimated && ($isManual || $type !== 'scrape_spend')) {
                $manualSpend += (float) ($event->amount ?? 0);
                $manualCount++;
            }

            if ($type === 'deal_closed') {
                $dealsClosed++;
                $manualCount++;
            }
        }

        return [
            'manualCampaignSpendUsd' => round($manualSpend, 2),
            'dealsClosed' => $dealsClosed,
            'manualEventCount' => $manualCount,
        ];
    }

    private function workspaceSlug(string $workspaceId): string
    {
        if ($workspaceId === '') {
            return '';
        }

        return (string) (Workspace::query()->where('id', $workspaceId)->value('slug') ?? '');
    }

    private function decodeMetadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function findProfileForEvent(int $projectId, OutreachEvent $event): ?CreatorProfile
    {
        if ($event->creator_profile_id) {
            $profile = CreatorProfile::query()
                ->where('project_id', $projectId)
                ->where('id', $event->creator_profile_id)
                ->first();
            if ($profile) {
                return $profile;
            }
        }

        $platform = Str::lower(trim((string) $event->platform));
        $handle = Str::lower(ltrim(trim((string) $event->handle), '@'));
        if ($platform === '' || $handle === '') {
            return null;
        }

        return CreatorProfile::query()
            ->where('project_id', $projectId)
            ->whereRaw("LOWER(COALESCE(platform, '')) = ?", [$platform])
            ->whereRaw("LOWER(TRIM(LEADING '@' FROM COALESCE(handle, ''))) = ?", [$handle])
            ->first();
    }

    private function applyEventToProfile(CreatorProfile $profile, OutreachEvent $event): void
    {
        $eventType = Str::upper(trim((string) $event->event_type));
        $eventAt = $event->sent_at ?: $event->created_at ?: now();
        $channel = trim((string) ($event->channel ?: $event->platform ?: $profile->platform ?: ''));
        $url = trim((string) ($event->url ?: ''));
        $advancedStates = ['replied', 'negotiating', 'accepted', 'declined', 'won', 'lost', 'archived'];
        $terminalStates = ['accepted', 'declined', 'won', 'lost', 'archived'];

        if (in_array($eventType, $this->strictOutreachSentEventTypes(), true)) {
            if (!in_array((string) $profile->lifecycle_state, $advancedStates, true)) {
                $profile->status = 'CONTACTED';
                $profile->lifecycle_state = 'contacted';
                $profile->follow_up_needed = true;
            }
            $profile->dm_sent_at = $profile->dm_sent_at ?: $eventAt;
            $profile->last_outreach_at = $eventAt;
            $profile->last_outreach_channel = $channel ?: $profile->last_outreach_channel;
            $profile->conversation_channel = $channel ?: $profile->conversation_channel;
            if ($url !== '') {
                $profile->conversation_url = $url;
            }
        }

        if (in_array($eventType, $this->strictReplyEventTypes(), true)) {
            if (!in_array((string) $profile->lifecycle_state, $terminalStates, true)) {
                $profile->status = 'REPLIED';
                $profile->lifecycle_state = 'replied';
            }
            $profile->responded_at = $profile->responded_at ?: $eventAt;
            $profile->follow_up_needed = false;
            $profile->conversation_channel = $channel ?: $profile->conversation_channel;
            if ($url !== '') {
                $profile->conversation_url = $url;
            }
        }

        if ($profile->isDirty()) {
            $profile->save();
        }
    }
}
