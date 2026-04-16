<?php

namespace App\Services;

use App\Models\CreatorProfile;
use App\Models\MessageTemplate;
use App\Models\Task;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class TaskQueueService
{
    private const TERMINAL_TASK_STATUSES = ['COMPLETED', 'DONE', 'SKIPPED', 'ARCHIVED'];
    private const OPEN_TASK_STATUSES = ['PENDING', 'IN_PROGRESS', 'SNOOZED'];
    private const TERMINAL_PROFILE_STATES = ['ARCHIVED', 'WON', 'LOST', 'DECLINED', 'POSTED'];
    private const REQUIRES_CONNECTION = ['instagram'];

    public function __construct(
        private GoogleSheetsService $sheets,
        private OutreachLogService $outreachLog,
        private InfluencerScoringService $scoring,
        private OperationalMirrorService $mirror,
        private ProjectResolverService $projects,
    ) {
    }

    public function listTasks(string $sheetId): array
    {
        $dbTasks = $this->listTasksFromDatabase($sheetId);
        if ($dbTasks !== null) {
            return $dbTasks;
        }

        if (str_starts_with($sheetId, 'workspace:')) {
            return [];
        }

        $rows = $this->sheets->getRows($sheetId, 'Task_Queue');

        usort($rows, function (array $a, array $b) {
            $priorityOrder = ['URGENT' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3];
            $pa = $priorityOrder[strtoupper((string) ($a['Priority'] ?? ''))] ?? 9;
            $pb = $priorityOrder[strtoupper((string) ($b['Priority'] ?? ''))] ?? 9;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcmp((string) ($a['Due_At'] ?? ''), (string) ($b['Due_At'] ?? ''));
        });

        return array_map(fn (array $row) => $this->normalizeSheetTaskRow($row), $rows);
    }

    public function listColdRetry(string $sheetId): array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            return [];
        }

        return Task::query()
            ->where('tasks.project_id', $project->id)
            ->coldRetry()
            ->get()
            ->map(function (Task $task) {
                return [
                    'taskId'        => (string) ($task->external_task_key ?: $task->id),
                    'taskType'      => (string) $task->task_type,
                    'platform'      => strtolower((string) ($task->platform ?: 'instagram')),
                    'handle'        => (string) ($task->handle ?: ''),
                    'profileUrl'    => (string) ($task->open_url ?: ''),
                    'status'        => 'archived',
                    'priority'      => $this->normalizePriority(strtoupper((string) ($task->priority ?: 'LOW'))),
                    'valueScore'    => (int) ($task->cp_value_score ?? 0),
                    'followers'     => (int) ($task->cp_followers_count ?? 0),
                    'profilePicUrl' => (string) ($task->cp_profile_pic_url ?? ''),
                    'followUpCount' => (int) ($task->follow_up_count ?? 0),
                    'notes'         => (string) ($task->notes ?: ''),
                    'completedAt'   => optional($task->completed_at)?->toDateTimeString() ?? '',
                ];
            })
            ->values()
            ->all();
    }

    public function getTaskSettings(string $sheetId): array
    {
        $workspace = $this->resolveWorkspaceForSheet($sheetId);
        $settings = $this->resolveTaskSettings($workspace);
        $role = $this->currentWorkspaceRole();

        return [
            'settings' => $settings,
            'canEdit' => $this->canEditTaskSettings($settings, $role),
            'role' => $role,
        ];
    }

    public function updateTaskSettings(string $sheetId, array $updates): array
    {
        $workspace = $this->resolveWorkspaceForSheet($sheetId);
        if (!$workspace) {
            throw new RuntimeException('Workspace not found for task settings.');
        }

        $settings = $this->resolveTaskSettings($workspace);
        $role = $this->currentWorkspaceRole();
        if (!$this->canEditTaskSettings($settings, $role)) {
            throw new RuntimeException('You do not have permission to change task settings.');
        }

        $merged = $this->sanitizeTaskSettings(array_replace_recursive($settings, $updates));
        $workspaceSettings = (array) ($workspace->settings ?? []);
        $workspaceSettings['taskAutomation'] = $merged;
        $workspace->settings = $workspaceSettings;
        $workspace->save();

        return [
            'settings' => $merged,
            'canEdit' => $this->canEditTaskSettings($merged, $role),
            'role' => $role,
        ];
    }

    public function snoozeTask(string $sheetId, string $taskId, Carbon $until, ?string $reason = null): array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if ($project) {
            $task = Task::query()
                ->where('project_id', $project->id)
                ->where(fn ($q) => $q->where('external_task_key', $taskId)->orWhere('id', $taskId))
                ->with('creatorProfile')
                ->firstOrFail();

            $meta = (array) ($task->metadata ?? []);
            if ($reason) {
                $meta['snooze_reason'] = $reason;
            }

            $task->status = 'SNOOZED';
            $task->snoozed_until = $until;
            $task->snooze_reason = $reason;
            $task->metadata = $meta;
            $task->save();

            if ($task->creatorProfile) {
                $task->creatorProfile->waiting_until = $until;
                $task->creatorProfile->next_action_at = $until;
                $task->creatorProfile->save();
            }

            $this->outreachLog->appendEvent($sheetId, [
                'Task_ID' => (string) ($task->external_task_key ?: $task->id),
                'creator_profile_id' => $task->creator_profile_id,
                'Platform' => (string) ($task->platform ?: ''),
                'Handle' => (string) ($task->handle ?: ''),
                'Channel' => (string) ($task->actionable_channel ?: $task->external_channel ?: $task->platform ?: ''),
                'Event_Type' => 'TASK_SNOOZED',
                'Status' => 'SNOOZED',
                'URL' => (string) ($task->conversation_url ?: $task->open_url ?: ''),
                'Notes' => $reason ?: 'Snoozed',
            ]);

            return [
                'taskId' => (string) ($task->external_task_key ?: $task->id),
                'status' => 'SNOOZED',
                'snoozedUntil' => $until->toIso8601String(),
            ];
        }

        if (str_starts_with($sheetId, 'workspace:')) {
            throw new RuntimeException('Task not found');
        }

        $task = $this->sheets->findFirstRowBy($sheetId, 'Task_Queue', 'Task_ID', $taskId);
        if (!$task) {
            throw new RuntimeException('Task not found');
        }

        $task['Status'] = 'SNOOZED';
        $task['Notes'] = trim(((string) ($task['Notes'] ?? '')) . ' ' . ($reason ?: 'Snoozed until ' . $until->toDateTimeString()));
        $this->sheets->updateAssocRow($sheetId, 'Task_Queue', (int) ($task['_row_number'] ?? 0), $task);

        return [
            'taskId' => $taskId,
            'status' => 'SNOOZED',
            'snoozedUntil' => $until->toIso8601String(),
        ];
    }

    public function generateInitialTasks(string $sheetId, array $options = []): array
    {
        $project = $this->projects->resolveByWorkbookId($sheetId);
        if ($project) {
            return $this->generateInitialTasksFromDatabase($sheetId, $options);
        }

        if (str_starts_with($sheetId, 'workspace:')) {
            return [
                'created' => 0,
                'eligible' => 0,
                'skipped_existing' => 0,
                'skipped_ineligible' => 0,
                'source' => 'workspace_runtime',
            ];
        }

        return $this->generateSheetFallbackTasks($sheetId, $options);
    }

    public function createManualTask(string $sheetId, array $payload): array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if ($project) {
            return $this->createManualTaskInDatabase($sheetId, $payload);
        }

        if (str_starts_with($sheetId, 'workspace:')) {
            throw new RuntimeException('Cannot create a manual task without a project workbook.');
        }

        $taskId = (string) Str::uuid();
        $row = [
            'Task_ID' => $taskId,
            'Platform' => strtolower((string) ($payload['platform'] ?? 'instagram')),
            'Handle' => (string) ($payload['handle'] ?? ''),
            'Task_Type' => (string) ($payload['taskType'] ?? 'DM_INVITE'),
            'Priority' => strtoupper((string) ($payload['priority'] ?? 'MEDIUM')),
            'Status' => 'PENDING',
            'Due_At' => (string) ($payload['dueAt'] ?? now()->toDateTimeString()),
            'Open_URL' => (string) ($payload['profileUrl'] ?? ''),
            'Message_Draft' => (string) ($payload['messageText'] ?? ''),
            'Template_ID' => '',
            'Created_At' => now()->toDateTimeString(),
            'Completed_At' => '',
            'Notes' => trim('Manual task: ' . (string) ($payload['notes'] ?? '')),
        ];

        $headers = $this->sheets->getHeaders($sheetId, 'Task_Queue');
        $this->sheets->appendAssocRows($sheetId, 'Task_Queue', [$row], $headers);

        return $this->normalizeSheetTaskRow($row);
    }

    public function completeTask(string $sheetId, string $taskId, array $payload = []): array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if ($project) {
            return $this->completeTaskInDatabase($sheetId, $taskId, $payload);
        }

        if (str_starts_with($sheetId, 'workspace:')) {
            throw new RuntimeException('Task not found');
        }

        $task = $this->sheets->findFirstRowBy($sheetId, 'Task_Queue', 'Task_ID', $taskId);
        if (!$task) {
            throw new RuntimeException('Task not found');
        }

        $status = strtoupper(trim((string) ($payload['status'] ?? 'COMPLETED')));
        $task['Status'] = $status;
        $task['Completed_At'] = in_array($status, ['COMPLETED', 'DONE', 'SKIPPED'], true)
            ? now()->toDateTimeString()
            : '';
        $task['Notes'] = trim(((string) ($task['Notes'] ?? '')) . ' ' . (string) ($payload['notes'] ?? ''));
        $this->sheets->updateAssocRow($sheetId, 'Task_Queue', (int) ($task['_row_number'] ?? 0), $task);

        return [
            'taskId' => $taskId,
            'status' => $status,
            'source' => 'google_sheets',
        ];
    }

    private function generateInitialTasksFromDatabase(string $sheetId, array $options = []): array
    {
        $project = $this->projects->resolveByWorkbookId($sheetId);
        $settings = $this->resolveTaskSettings($this->resolveWorkspaceForSheet($sheetId, $project));
        $limitRequested = max(1, (int) ($options['limit'] ?? ($settings['max_new_tasks_per_generation'] ?? 12)));
        $maxActiveTasks = (int) ($settings[$this->timePressureEnabled($settings) ? 'time_pressure_active_task_limit' : 'max_active_tasks'] ?? 18);
        $maxNewTasksPerGeneration = (int) ($settings['max_new_tasks_per_generation'] ?? 12);

        $activeOpenCount = Task::query()
            ->where('project_id', $project->id)
            ->whereIn('status', self::OPEN_TASK_STATUSES)
            ->count();

        $availableSlots = max(0, $maxActiveTasks - $activeOpenCount);
        $finalLimit = min($limitRequested, $maxNewTasksPerGeneration, max(0, $availableSlots));

        $profilesQuery = CreatorProfile::query()
            ->with('creator')
            ->where('project_id', $project->id);

        $targetProfileIds = array_values(array_unique(array_filter(array_map('strval', (array) ($options['profileIds'] ?? [])))));
        if ($targetProfileIds !== []) {
            $profilesQuery->whereIn('id', $targetProfileIds);
        }

        $targetRowNumbers = array_values(array_unique(array_filter(array_map('intval', (array) ($options['rowNumbers'] ?? [])), fn (int $n) => $n > 0)));
        if ($targetProfileIds === [] && $targetRowNumbers !== []) {
            $profilesQuery->where(function ($q) use ($targetRowNumbers) {
                foreach ($targetRowNumbers as $rowNumber) {
                    $q->orWhere('source_reference', 'Creators_CRM:' . $rowNumber);
                }
            });
        }

        $profiles = $profilesQuery
            ->orderByDesc('value_score')
            ->orderByDesc('followers_count')
            ->get();

        if ($profiles->isEmpty() || $finalLimit <= 0) {
            return [
                'created' => 0,
                'eligible' => 0,
                'skipped_existing' => 0,
                'skipped_ineligible' => $profiles->count(),
                'taskSheet' => 'Task_Queue',
                'sourceProfileIds' => $targetProfileIds,
                'sourceRowNumbers' => $targetRowNumbers,
                'source' => 'database',
                'capacity' => [
                    'activeOpenCount' => $activeOpenCount,
                    'availableSlots' => $availableSlots,
                ],
            ];
        }

        $openByProfileId = Task::query()
            ->where('project_id', $project->id)
            ->whereIn('status', self::OPEN_TASK_STATUSES)
            ->whereNotNull('creator_profile_id')
            ->pluck('creator_profile_id')
            ->flip()
            ->all();

        $templates = MessageTemplate::query()
            ->where('project_id', $project->id)
            ->get()
            ->all();

        $candidates = [];
        $eligible = 0;
        $skippedIneligible = 0;
        $skippedExisting = 0;

        foreach ($profiles as $profile) {
            if (isset($openByProfileId[$profile->id])) {
                $skippedExisting++;
                continue;
            }

            $candidate = $this->buildCandidateForProfile($profile, $settings);
            if (!$candidate) {
                $skippedIneligible++;
                continue;
            }

            $eligible++;
            $candidates[] = $candidate;
        }

        usort($candidates, fn (array $a, array $b) => ($b['rank_score'] ?? 0) <=> ($a['rank_score'] ?? 0));

        $created = 0;
        $logEvents = [];

        foreach (array_slice($candidates, 0, $finalLimit) as $candidate) {
            /** @var CreatorProfile $profile */
            $profile = $candidate['profile'];
            $task = $this->createDatabaseTask($project->id, $profile, $candidate, $templates);
            $logEvents[] = [
                'Task_ID' => (string) ($task->external_task_key ?: $task->id),
                'creator_profile_id' => $profile->id,
                'Platform' => (string) ($task->platform ?: ''),
                'Handle' => (string) ($task->handle ?: ''),
                'Channel' => (string) ($task->actionable_channel ?: $task->platform ?: ''),
                'Event_Type' => 'TASK_CREATED',
                'Template_ID' => (string) ($task->messageTemplate?->angle_id ?: ''),
                'Status' => 'PENDING',
                'URL' => (string) ($task->conversation_url ?: $task->open_url ?: ''),
                'Notes' => (string) ($task->group_label ?: $task->task_type),
            ];
            $created++;
        }

        if ($logEvents !== []) {
            $this->outreachLog->appendEvents($sheetId, $logEvents);
        }

        return [
            'created' => $created,
            'eligible' => $eligible,
            'skipped_existing' => $skippedExisting,
            'skipped_ineligible' => $skippedIneligible,
            'taskSheet' => 'Task_Queue',
            'sourceProfileIds' => $targetProfileIds,
            'sourceRowNumbers' => $targetRowNumbers,
            'source' => 'database',
            'capacity' => [
                'activeOpenCount' => $activeOpenCount,
                'availableSlots' => $availableSlots,
                'limitRequested' => $limitRequested,
                'limitApplied' => $finalLimit,
            ],
            'timePressureMode' => $this->timePressureEnabled($settings),
        ];
    }

    private function listTasksFromDatabase(string $sheetId): ?array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            return null;
        }

        $tasks = Task::query()
            ->where('project_id', $project->id)
            ->with(['creatorProfile.creator', 'messageTemplate'])
            ->orderByRaw("CASE priority WHEN 'URGENT' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'MEDIUM' THEN 3 WHEN 'LOW' THEN 4 ELSE 5 END")
            ->orderBy('due_at')
            ->orderBy('created_at')
            ->get();

        return $tasks->map(fn (Task $task) => $this->normalizeDbTask($task))->values()->all();
    }

    private function createManualTaskInDatabase(string $sheetId, array $payload): array
    {
        $project = $this->projects->resolveByWorkbookId($sheetId);
        $settings = $this->resolveTaskSettings($this->resolveWorkspaceForSheet($sheetId, $project));
        $handle = trim((string) ($payload['handle'] ?? ''));
        $platform = strtolower((string) ($payload['platform'] ?? 'instagram'));

        $profile = CreatorProfile::query()
            ->where('project_id', $project->id)
            ->where('platform', $platform)
            ->where('handle', $handle)
            ->first();

        $candidate = [
            'profile' => $profile,
            'task_type' => (string) ($payload['taskType'] ?? 'DM_INVITE'),
            'priority' => strtoupper((string) ($payload['priority'] ?? 'MEDIUM')),
            'due_at' => $payload['dueAt'] ? Carbon::parse((string) $payload['dueAt']) : now(),
            'rank_score' => 0,
            'actionable_channel' => $platform,
            'conversation_url' => (string) ($payload['profileUrl'] ?? ($profile?->dm_link ?: $profile?->profile_url ?: '')),
            'message_draft' => (string) ($payload['messageText'] ?? ''),
            'notes' => trim((string) ($payload['notes'] ?? '')),
            'metadata' => [
                'source_rule' => 'manual',
                'manual_created' => true,
            ],
        ];

        $task = $this->createDatabaseTask($project->id, $profile, $candidate, MessageTemplate::query()->where('project_id', $project->id)->get()->all(), true);
        return $this->normalizeDbTask($task);
    }

    private function completeTaskInDatabase(string $sheetId, string $taskId, array $payload = []): array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            throw new RuntimeException('Project not found for sheet: ' . $sheetId);
        }

        $task = Task::query()
            ->where('project_id', $project->id)
            ->where(fn ($q) => $q->where('external_task_key', $taskId)->orWhere('id', $taskId))
            ->with(['creatorProfile.creator', 'messageTemplate'])
            ->firstOrFail();

        $settings = $this->resolveTaskSettings($this->resolveWorkspaceForSheet($sheetId, $project));
        $status = strtoupper(trim((string) ($payload['status'] ?? 'COMPLETED')));
        $outcome = trim((string) ($payload['outcome'] ?? ''));
        $skipReason = trim((string) ($payload['skipReason'] ?? ''));
        $skipReasonDetail = trim((string) ($payload['skipReasonDetail'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));
        $externalChannel = trim((string) ($payload['externalChannel'] ?? $payload['responseChannel'] ?? ''));
        $conversationUrl = trim((string) ($payload['conversationUrl'] ?? ''));
        $senderAccount = trim((string) ($payload['sender_account'] ?? ''));

        if (array_key_exists('template_id', $payload) && $payload['template_id'] !== null) {
            $templateId = trim((string) $payload['template_id']);
            if ($templateId !== '') {
                $template = MessageTemplate::query()
                    ->where('project_id', $project->id)
                    ->where('angle_id', $templateId)
                    ->first();
                $task->message_template_id = $template?->id;
            }
        }

        if (array_key_exists('message_draft', $payload) && $payload['message_draft'] !== null) {
            $task->message_draft = (string) $payload['message_draft'];
        }

        $meta = (array) ($task->metadata ?? []);
        if ($outcome !== '') {
            $meta['completion_outcome'] = $outcome;
        }
        if ($skipReason !== '') {
            $meta['skip_reason'] = $skipReason;
        }
        if ($skipReasonDetail !== '') {
            $meta['skip_reason_detail'] = $skipReasonDetail;
        }
        if ($externalChannel !== '') {
            $meta['external_channel'] = $externalChannel;
        }
        if ($conversationUrl !== '') {
            $meta['conversation_url'] = $conversationUrl;
        }

        $task->status = $status;
        $task->completed_at = in_array($status, ['COMPLETED', 'DONE', 'SKIPPED'], true) ? now() : null;
        $task->completion_outcome = $outcome !== '' ? $outcome : null;
        $task->skip_reason = $skipReason !== '' ? $skipReason : null;
        $task->skip_reason_detail = $skipReasonDetail !== '' ? $skipReasonDetail : null;
        $task->external_channel = $externalChannel !== '' ? $externalChannel : $task->external_channel;
        $task->conversation_url = $conversationUrl !== '' ? $conversationUrl : $task->conversation_url;
        $task->notes = trim(((string) ($task->notes ?? '')) . ($notes !== '' ? ' ' . $notes : ''));
        $task->metadata = $meta;

        if ($status === 'COMPLETED' && in_array($task->task_type, ['DM_INVITE', 'EMAIL_SEND', 'DM_FOLLOWUP'], true)) {
            $task->follow_up_count = ((int) ($task->follow_up_count ?? 0)) + 1;
        }

        $task->save();

        $profile = $task->creatorProfile;
        if ($profile) {
            $this->applyTaskResultToProfile($profile, $task, $payload, $settings);
            $profile->save();
            $this->maybeCreateImmediateNextTask($project->id, $profile, $task, $settings);
        }

        $eventType = $this->eventTypeFromTask((string) $task->task_type, $status, $outcome ?: $skipReason);
        $eventId = $this->outreachLog->appendEvent($sheetId, [
            'Task_ID' => (string) ($task->external_task_key ?: $task->id),
            'creator_profile_id' => $task->creator_profile_id,
            'Platform' => (string) ($task->platform ?: ''),
            'Handle' => (string) ($task->handle ?: ''),
            'Channel' => (string) ($task->external_channel ?: $task->actionable_channel ?: $task->platform ?: ''),
            'Event_Type' => $eventType,
            'Template_ID' => (string) ($task->messageTemplate?->angle_id ?: ''),
            'Sender_Account' => $senderAccount,
            'Status' => $status,
            'URL' => (string) ($task->conversation_url ?: $task->open_url ?: ''),
            'Notes' => implode(' | ', array_values(array_filter([$notes, $outcome, $skipReason, $skipReasonDetail]))),
        ]);

        return [
            'taskId' => (string) ($task->external_task_key ?: $task->id),
            'eventId' => $eventId,
            'status' => $task->status,
            'createdFollowUpTask' => $profile ? $this->findNewestOpenTaskForProfile($project->id, $profile->id, $task->id) : null,
            'source' => 'database',
        ];
    }

    private function buildCandidateForProfile(CreatorProfile $profile, array $settings): ?array
    {
        $status = strtoupper(trim((string) ($profile->status ?: $profile->lifecycle_state ?: '')));
        if (in_array($status, self::TERMINAL_PROFILE_STATES, true)) {
            return null;
        }

        $state = $this->profileAutomationState($profile);
        $now = now();

        foreach (['task_suppressed_until', 'waiting_until', 'next_action_at'] as $field) {
            if ($profile->{$field} instanceof Carbon && $profile->{$field}->isFuture() && $field !== 'next_action_at') {
                return null;
            }
        }

        if (!empty($state['external_conversation_active'])) {
            $due = $this->coerceCarbon($profile->next_action_at ?: ($state['external_check_in_at'] ?? null));
            if ($due && $due->isFuture()) {
                return null;
            }
            return $this->candidateArray(
                profile: $profile,
                taskType: 'CHECK_IN',
                priority: $this->priorityFromProfile($profile, true),
                dueAt: $due ?: $now,
                actionableChannel: (string) ($state['external_channel'] ?? $profile->conversation_channel ?: $profile->platform),
                conversationUrl: (string) ($state['external_conversation_url'] ?? $profile->conversation_url ?: $profile->dm_link ?: $profile->profile_url ?: ''),
                notes: 'Check the outside-app conversation and log the latest status.',
                metadata: [
                    'source_rule' => 'external_conversation_check_in',
                    'group_context' => 'external_conversation',
                ]
            );
        }

        if ($profile->responded_at !== null && !$profile->accepted_flag && !in_array($status, ['NEGOTIATING', 'ACCEPTED'], true)) {
            return $this->candidateArray(
                profile: $profile,
                taskType: 'REVIEW_CREATOR',
                priority: 'URGENT',
                dueAt: $now,
                actionableChannel: (string) ($profile->conversation_channel ?: $profile->platform),
                conversationUrl: (string) ($profile->conversation_url ?: $profile->dm_link ?: $profile->profile_url ?: ''),
                notes: 'The creator replied. Decide the next move and keep the thread organized.',
                metadata: [
                    'source_rule' => 'reply_review',
                    'group_context' => 'reply_review',
                ]
            );
        }

        if ($profile->follow_up_due_at instanceof Carbon) {
            if ($profile->follow_up_due_at->isFuture()) {
                return null;
            }

            $attempts = (int) ($state['follow_up_attempts'] ?? 1);
            $maxFollowUps = (int) ($settings['max_follow_up_attempts'] ?? 2);
            if ($attempts >= $maxFollowUps) {
                return $this->candidateArray(
                    profile: $profile,
                    taskType: 'CHECK_IN',
                    priority: $this->priorityFromProfile($profile, true),
                    dueAt: $profile->follow_up_due_at,
                    actionableChannel: (string) ($profile->conversation_channel ?: $profile->platform),
                    conversationUrl: (string) ($profile->conversation_url ?: $profile->dm_link ?: $profile->profile_url ?: ''),
                    notes: 'No reply after several attempts. Decide whether to keep watching or archive.',
                    metadata: [
                        'source_rule' => 'follow_up_exhausted',
                        'group_context' => 'decision_check_in',
                    ]
                );
            }

            $followUpTaskType = $this->determineFollowUpTaskTypeFromProfile($profile, $settings, $state);
            $followUpChannel = $followUpTaskType === 'EMAIL_SEND'
                ? 'email'
                : strtolower((string) ($profile->conversation_channel ?: $profile->preferred_channel ?: $profile->platform ?: 'instagram'));

            return $this->candidateArray(
                profile: $profile,
                taskType: $followUpTaskType,
                priority: $this->priorityFromProfile($profile, true),
                dueAt: $profile->follow_up_due_at,
                actionableChannel: $followUpChannel,
                conversationUrl: (string) ($profile->conversation_url ?: $profile->dm_link ?: $profile->profile_url ?: ''),
                notes: $this->followUpNotesForProfile($profile, $followUpTaskType),
                metadata: [
                    'source_rule' => 'follow_up_due',
                    'group_context' => 'follow_up',
                    'follow_up_attempts' => $attempts,
                    'follow_up_variant' => true,
                ]
            );
        }

        if ($profile->dm_sent_at !== null && $profile->responded_at === null) {
            return null;
        }

        $initialTaskType = $this->determineInitialTaskTypeFromProfile($profile, $settings);
        if ($initialTaskType === null) {
            return null;
        }

        return $this->candidateArray(
            profile: $profile,
            taskType: $initialTaskType,
            priority: $this->priorityFromProfile($profile),
            dueAt: $now,
            actionableChannel: $initialTaskType === 'EMAIL_SEND'
                ? 'email'
                : strtolower((string) ($profile->platform ?: 'instagram')),
            conversationUrl: (string) ($profile->dm_link ?: $profile->profile_url ?: ''),
            notes: $this->defaultNotesForTaskType($initialTaskType),
            metadata: [
                'source_rule' => 'initial_task_selection',
                'group_context' => str_starts_with($initialTaskType, 'DM') || $initialTaskType === 'EMAIL_SEND'
                    ? 'first_outreach'
                    : 'warmup',
                'value_score' => (int) ($profile->value_score ?? 0),
                'value_tier' => $this->scoring->tier((int) ($profile->value_score ?? 0)),
                'time_pressure_mode' => $this->timePressureEnabled($settings),
            ]
        );
    }

    private function determineFollowUpTaskTypeFromProfile(CreatorProfile $profile, array $settings = [], array $state = []): string
    {
        $platform = strtolower(trim((string) ($profile->platform ?: '')));
        $preferredChannel = strtoupper(trim((string) ($profile->preferred_channel ?: 'DM')));
        $hasEmail = filled(optional($profile->creator)->primary_email);
        $timePressure = $this->timePressureEnabled($settings);

        if ($preferredChannel === 'EMAIL' && $hasEmail) {
            return 'EMAIL_SEND';
        }

        if ($platform === 'instagram') {
            if (!$timePressure && empty($state['warmup_follow_request_completed']) && empty($state['warmup_follow_request_sent'])) {
                return 'FOLLOW_REQUEST';
            }

            return 'COMMENT_ON_POST';
        }

        if ($platform === 'tiktok') {
            return 'COMMENT_ON_POST';
        }

        if ($platform === 'email' && $hasEmail) {
            return 'EMAIL_SEND';
        }

        return 'DM_FOLLOWUP';
    }

    private function followUpNotesForProfile(CreatorProfile $profile, string $taskType): string
    {
        return match ($taskType) {
            'FOLLOW_REQUEST' => 'This creator has not replied. Send a follow request to warm the relationship before trying again.',
            'COMMENT_ON_POST' => 'This creator has not replied. Leave a natural public comment on a recent post as the next soft touch instead of forcing another DM.',
            'EMAIL_SEND' => 'No reply yet. Use the available email as the next direct outreach channel.',
            default => 'No reply logged yet. Send a thoughtful follow-up or explicitly snooze or skip with a reason.',
        };
    }

    private function determineInitialTaskTypeFromProfile(CreatorProfile $profile, array $settings = []): ?string
    {
        $preferredChannel = strtoupper(trim((string) ($profile->preferred_channel ?: 'DM')));
        $platform = strtolower(trim((string) ($profile->platform ?: '')));
        $hasEmail = filled(optional($profile->creator)->primary_email);
        $state = $this->profileAutomationState($profile);
        $timePressure = $this->timePressureEnabled($settings);
        $score = (int) ($profile->value_score ?? 0);
        $highValueThreshold = (int) ($settings['high_value_threshold'] ?? 75);
        $mediumValueThreshold = (int) ($settings['medium_value_threshold'] ?? 50);
        $warmupEnabled = (bool) ($settings['high_value_warmup_enabled'] ?? true);

        if ($profile->accepted_flag && $profile->dm_sent_at === null) {
            return 'DM_INVITE';
        }

        if ($profile->follow_up_due_at && $profile->responded_at === null) {
            return 'DM_FOLLOWUP';
        }

        if ($profile->responded_at !== null && !$profile->accepted_flag) {
            return 'REVIEW_CREATOR';
        }

        if ($warmupEnabled && !$timePressure) {
            if ($score >= $highValueThreshold) {
                if ($platform === 'instagram' && empty($state['warmup_follow_request_completed']) && empty($state['warmup_follow_request_sent'])) {
                    return 'FOLLOW_REQUEST';
                }
                if (empty($state['warmup_comment_completed'])) {
                    return 'COMMENT_ON_POST';
                }
            } elseif ($score >= $mediumValueThreshold && $platform === 'instagram' && empty($state['warmup_follow_request_completed']) && empty($state['warmup_follow_request_sent'])) {
                return 'FOLLOW_REQUEST';
            }
        }

        if ($preferredChannel === 'EMAIL' && $hasEmail) {
            return 'EMAIL_SEND';
        }

        if (in_array($platform, self::REQUIRES_CONNECTION, true) && !$timePressure) {
            if (empty($state['warmup_follow_request_sent']) && empty($state['warmup_follow_request_completed'])) {
                return 'FOLLOW_REQUEST';
            }
        }

        if ($platform === '') {
            return null;
        }

        return 'DM_INVITE';
    }

    private function applyTaskResultToProfile(CreatorProfile $profile, Task $task, array $payload, array $settings): void
    {
        $status = strtoupper((string) ($task->status ?? 'PENDING'));
        $taskType = (string) $task->task_type;
        $outcome = trim((string) ($payload['outcome'] ?? $task->completion_outcome ?? ''));
        $skipReason = trim((string) ($payload['skipReason'] ?? $task->skip_reason ?? ''));
        $externalChannel = trim((string) ($payload['externalChannel'] ?? $payload['responseChannel'] ?? $task->external_channel ?? ''));
        $conversationUrl = trim((string) ($payload['conversationUrl'] ?? $task->conversation_url ?? ''));
        $markReplied = (bool) ($payload['markReplied'] ?? false);
        $now = now();

        $state = $this->profileAutomationState($profile);
        $state['last_task_type'] = $taskType;
        $state['last_task_status'] = $status;
        $state['last_task_outcome'] = $outcome !== '' ? $outcome : $skipReason;
        $state['last_task_completed_at'] = $now->toIso8601String();

        if ($status === 'SKIPPED') {
            $profile->last_task_outcome = $skipReason !== '' ? $skipReason : 'skipped';
            if (in_array($skipReason, ['replied_elsewhere', 'conversation_active_elsewhere'], true)) {
                $this->markExternalConversationActive($profile, $state, $settings, $externalChannel, $conversationUrl);
            } elseif (in_array($skipReason, ['not_a_fit', 'low_priority', 'inactive_creator', 'duplicate'], true)) {
                $profile->task_suppressed_until = $now->copy()->addDays((int) ($settings['archive_snooze_days'] ?? 30));
            } elseif ($skipReason === 'missing_info') {
                $profile->waiting_until = $now->copy()->addDays(2);
                $profile->next_action_at = $profile->waiting_until;
            }
            $profile->automation_state = $state;
            return;
        }

        if (!in_array($status, ['COMPLETED', 'DONE'], true)) {
            $profile->automation_state = $state;
            return;
        }

        switch ($taskType) {
            case 'FOLLOW_REQUEST':
                $profile->status = 'FOLLOW_REQUEST_SENT';
                $profile->lifecycle_state = 'warming';
                $state['warmup_follow_request_sent'] = true;
                $state['warmup_follow_request_completed'] = true;
                if (!empty(($task->metadata ?? [])['follow_up_variant'])) {
                    if ($markReplied || in_array($outcome, ['creator_replied', 'replied_elsewhere', 'conversation_active_elsewhere'], true)) {
                        $this->markExternalConversationActive($profile, $state, $settings, $externalChannel ?: (string) ($task->actionable_channel ?: $task->platform), $conversationUrl ?: (string) ($task->conversation_url ?: $task->open_url));
                        $profile->responded_at = $profile->responded_at ?: $now;
                        $profile->status = 'REPLIED';
                        $profile->lifecycle_state = 'replied';
                        break;
                    }
                    $attempts = max(1, ((int) ($state['follow_up_attempts'] ?? 1)) + 1);
                    $state['follow_up_attempts'] = $attempts;
                    $profile->last_outreach_at = $now;
                    $profile->last_outreach_channel = (string) ($task->actionable_channel ?: $task->platform ?: 'instagram');
                    $profile->conversation_channel = $profile->last_outreach_channel;
                    $profile->conversation_url = $conversationUrl !== '' ? $conversationUrl : (string) ($task->conversation_url ?: $task->open_url ?: $profile->conversation_url ?: '');
                    $profile->waiting_until = $now->copy()->addDays((int) ($settings['reply_check_in_delay_days'] ?? 2));
                    $profile->next_action_at = $profile->waiting_until;
                    $profile->follow_up_due_at = $profile->waiting_until;
                }
                break;

            case 'COMMENT_ON_POST':
                $profile->comment_attempted_at = $now;
                $profile->status = 'COMMENT_ATTEMPTED';
                $profile->lifecycle_state = 'warming';
                $state['warmup_comment_completed'] = true;
                if (!empty(($task->metadata ?? [])['follow_up_variant'])) {
                    if ($markReplied || in_array($outcome, ['creator_replied', 'replied_elsewhere', 'conversation_active_elsewhere'], true)) {
                        $this->markExternalConversationActive($profile, $state, $settings, $externalChannel ?: (string) ($task->actionable_channel ?: $task->platform), $conversationUrl ?: (string) ($task->conversation_url ?: $task->open_url));
                        $profile->responded_at = $profile->responded_at ?: $now;
                        $profile->status = 'REPLIED';
                        $profile->lifecycle_state = 'replied';
                        break;
                    }
                    $attempts = max(1, ((int) ($state['follow_up_attempts'] ?? 1)) + 1);
                    $state['follow_up_attempts'] = $attempts;
                    $profile->last_outreach_at = $now;
                    $profile->last_outreach_channel = (string) ($task->actionable_channel ?: $task->platform ?: 'instagram');
                    $profile->conversation_channel = $profile->last_outreach_channel;
                    $profile->conversation_url = $conversationUrl !== '' ? $conversationUrl : (string) ($task->conversation_url ?: $task->open_url ?: $profile->conversation_url ?: '');
                    $profile->waiting_until = $now->copy()->addDays((int) ($settings['reply_check_in_delay_days'] ?? 2));
                    $profile->next_action_at = $profile->waiting_until;
                    $profile->follow_up_due_at = $profile->waiting_until;
                }
                break;

            case 'DM_INVITE':
            case 'EMAIL_SEND':
                if ($markReplied || in_array($outcome, ['creator_replied', 'replied_elsewhere', 'conversation_active_elsewhere'], true)) {
                    $this->markExternalConversationActive($profile, $state, $settings, $externalChannel ?: (string) ($task->actionable_channel ?: $task->platform), $conversationUrl ?: (string) ($task->conversation_url ?: $task->open_url));
                    $profile->responded_at = $profile->responded_at ?: $now;
                    $profile->status = 'REPLIED';
                    $profile->lifecycle_state = 'replied';
                    break;
                }

                $profile->status = 'CONTACTED';
                $profile->lifecycle_state = 'contacted';
                $profile->dm_sent_at = $now;
                $profile->follow_up_needed = true;
                $profile->last_outreach_at = $now;
                $profile->last_outreach_channel = (string) ($task->actionable_channel ?: $task->platform ?: 'instagram');
                $profile->conversation_channel = $profile->last_outreach_channel;
                $profile->conversation_url = $conversationUrl !== '' ? $conversationUrl : (string) ($task->conversation_url ?: $task->open_url ?: '');
                $delay = (int) ($settings[$this->timePressureEnabled($settings) ? 'aggressive_follow_up_delay_days' : 'follow_up_delay_days'] ?? 5);
                $profile->follow_up_due_at = $now->copy()->addDays(max(1, $delay));
                $profile->waiting_until = $profile->follow_up_due_at;
                $profile->next_action_at = $profile->follow_up_due_at;
                $state['follow_up_attempts'] = max(1, (int) ($state['follow_up_attempts'] ?? 0));
                $state['external_conversation_active'] = false;
                break;

            case 'DM_FOLLOWUP':
                if ($markReplied || in_array($outcome, ['creator_replied', 'replied_elsewhere', 'conversation_active_elsewhere'], true)) {
                    $this->markExternalConversationActive($profile, $state, $settings, $externalChannel ?: (string) ($task->actionable_channel ?: $task->platform), $conversationUrl ?: (string) ($task->conversation_url ?: $task->open_url));
                    $profile->responded_at = $profile->responded_at ?: $now;
                    $profile->status = 'REPLIED';
                    $profile->lifecycle_state = 'replied';
                    break;
                }

                $profile->status = 'FOLLOWED_UP';
                $profile->lifecycle_state = 'contacted';
                $profile->last_outreach_at = $now;
                $profile->last_outreach_channel = (string) ($task->actionable_channel ?: $task->platform ?: 'instagram');
                $attempts = ((int) ($state['follow_up_attempts'] ?? 1)) + 1;
                $state['follow_up_attempts'] = $attempts;
                $maxFollowUps = (int) ($settings['max_follow_up_attempts'] ?? 2);
                if ($attempts >= $maxFollowUps) {
                    $profile->follow_up_due_at = null;
                    $profile->waiting_until = $now->copy()->addDays((int) ($settings['reply_check_in_delay_days'] ?? 2));
                    $profile->next_action_at = $profile->waiting_until;
                } else {
                    $delay = (int) ($settings[$this->timePressureEnabled($settings) ? 'aggressive_follow_up_delay_days' : 'follow_up_delay_days'] ?? 5);
                    $profile->follow_up_due_at = $now->copy()->addDays(max(1, $delay));
                    $profile->waiting_until = $profile->follow_up_due_at;
                    $profile->next_action_at = $profile->follow_up_due_at;
                }
                break;

            case 'REVIEW_CREATOR':
                if (in_array($outcome, ['approved', 'move_to_outreach'], true)) {
                    $state['needs_reply_review'] = false;
                } elseif (in_array($outcome, ['rejected', 'not_a_fit'], true)) {
                    $profile->task_suppressed_until = $now->copy()->addDays((int) ($settings['archive_snooze_days'] ?? 30));
                }
                break;

            case 'CHECK_IN':
                if (in_array($outcome, ['needs_reply', 'conversation_active_elsewhere'], true)) {
                    $this->markExternalConversationActive($profile, $state, $settings, $externalChannel ?: (string) ($profile->conversation_channel ?: $profile->platform), $conversationUrl ?: (string) ($profile->conversation_url ?: $task->conversation_url ?: $task->open_url));
                } elseif (in_array($outcome, ['archive', 'lost'], true)) {
                    $profile->status = 'ARCHIVED';
                    $profile->lifecycle_state = 'archived';
                    $profile->task_suppressed_until = $now->copy()->addYears(5);
                } else {
                    $profile->waiting_until = $now->copy()->addDays((int) ($settings['external_check_in_days'] ?? 3));
                    $profile->next_action_at = $profile->waiting_until;
                }
                break;

            case 'NEGOTIATE_TERMS':
                $profile->status = 'NEGOTIATING';
                $profile->lifecycle_state = 'negotiating';
                break;

            case 'CONFIRM_ACCEPTED':
                $profile->accepted_flag = true;
                $profile->status = 'ACCEPTED';
                $profile->lifecycle_state = 'accepted';
                break;

            case 'ARCHIVE_CREATOR':
                $profile->status = 'ARCHIVED';
                $profile->lifecycle_state = 'archived';
                $profile->task_suppressed_until = $now->copy()->addYears(5);
                break;
        }

        $profile->last_task_outcome = $outcome !== '' ? $outcome : $taskType;
        $profile->automation_state = $state;
    }

    private function maybeCreateImmediateNextTask(string $projectId, CreatorProfile $profile, Task $task, array $settings): void
    {
        if (!in_array($task->status, ['COMPLETED', 'DONE'], true)) {
            return;
        }

        $outcome = strtolower((string) ($task->completion_outcome ?: ''));
        $taskType = (string) $task->task_type;
        $nextTaskType = null;

        if (in_array($taskType, ['FOLLOW_REQUEST', 'COMMENT_ON_POST'], true)) {
            if (!empty(($task->metadata ?? [])['follow_up_variant'])) {
                return;
            }
            if (!$this->timePressureEnabled($settings) && !in_array($outcome, ['not_a_fit', 'archive', 'lost'], true)) {
                $nextTaskType = $this->determineInitialTaskTypeFromProfile($profile, array_merge($settings, ['high_value_warmup_enabled' => false]));
            }
        } elseif ($taskType === 'REVIEW_CREATOR') {
            if (in_array($outcome, ['approved', 'move_to_outreach'], true)) {
                $nextTaskType = $this->determineInitialTaskTypeFromProfile($profile, array_merge($settings, ['high_value_warmup_enabled' => false]));
            } elseif (in_array($outcome, ['negotiate', 'proposal'], true)) {
                $nextTaskType = 'NEGOTIATE_TERMS';
            }
        } elseif ($taskType === 'CHECK_IN') {
            if (in_array($outcome, ['needs_reply', 'conversation_active_elsewhere'], true)) {
                $nextTaskType = 'REVIEW_CREATOR';
            }
        } elseif (in_array($taskType, ['DM_INVITE', 'EMAIL_SEND', 'DM_FOLLOWUP'], true) && in_array($outcome, ['creator_replied'], true)) {
            $nextTaskType = 'REVIEW_CREATOR';
        }

        if (!$nextTaskType) {
            return;
        }

        $exists = Task::query()
            ->where('project_id', $projectId)
            ->where('creator_profile_id', $profile->id)
            ->where('task_type', $nextTaskType)
            ->whereIn('status', self::OPEN_TASK_STATUSES)
            ->exists();

        if ($exists) {
            return;
        }

        $candidate = $this->candidateArray(
            profile: $profile,
            taskType: $nextTaskType,
            priority: $this->priorityFromProfile($profile, true),
            dueAt: now()->addHours((int) ($settings['warmup_gap_hours'] ?? 12)),
            actionableChannel: $nextTaskType === 'EMAIL_SEND' ? 'email' : strtolower((string) ($profile->conversation_channel ?: $profile->platform ?: 'instagram')),
            conversationUrl: (string) ($profile->conversation_url ?: $profile->dm_link ?: $profile->profile_url ?: ''),
            notes: 'Auto-promoted after completing ' . $taskType,
            metadata: [
                'source_rule' => 'immediate_next_step',
                'parent_task_id' => (string) ($task->external_task_key ?: $task->id),
                'group_context' => 'next_step',
            ]
        );

        $this->createDatabaseTask($projectId, $profile, $candidate, MessageTemplate::query()->where('project_id', $projectId)->get()->all());
    }

    private function createDatabaseTask(string $projectId, ?CreatorProfile $profile, array $candidate, array $templates, bool $manual = false): Task
    {
        $taskType = (string) ($candidate['task_type'] ?? 'DM_INVITE');
        $platform = strtolower((string) ($profile?->platform ?: ($candidate['actionable_channel'] ?? 'instagram')));
        $template = $profile ? $this->pickTemplateFromDatabase($templates, $platform, $taskType) : null;
        $groupMeta = $this->groupMetaForTask($taskType, (array) ($candidate['metadata'] ?? []), $manual);
        $messageDraft = (string) ($candidate['message_draft'] ?? ($profile ? $this->buildMessageDraftFromProfile($template, $profile, $taskType) : ''));
        $taskId = (string) Str::uuid();

        return Task::create([
            'project_id' => $projectId,
            'creator_profile_id' => $profile?->id,
            'message_template_id' => $template?->id,
            'external_task_key' => $taskId,
            'platform' => $platform,
            'handle' => (string) ($profile?->handle ?: ''),
            'task_type' => $taskType,
            'priority' => strtoupper((string) ($candidate['priority'] ?? 'MEDIUM')),
            'status' => 'PENDING',
            'due_at' => $candidate['due_at'] instanceof Carbon ? $candidate['due_at'] : now(),
            'snoozed_until' => null,
            'follow_up_count' => (int) ($candidate['metadata']['follow_up_attempts'] ?? 0),
            'platform_connection_state' => $this->initialConnectionState($platform, $profile),
            'open_url' => (string) ($candidate['conversation_url'] ?? ($profile?->dm_link ?: $profile?->profile_url ?: '')),
            'message_draft' => $messageDraft,
            'source_provider' => $manual ? 'manual' : 'database',
            'source_reference' => $profile ? 'creator_profile:' . $profile->id : 'manual',
            'notes' => (string) ($candidate['notes'] ?? ''),
            'metadata' => array_merge((array) ($candidate['metadata'] ?? []), ['creator_profile_id' => $profile?->id]),
            'group_key' => $groupMeta['group_key'],
            'group_label' => $groupMeta['group_label'],
            'group_type' => $groupMeta['group_type'],
            'actionable_channel' => (string) ($candidate['actionable_channel'] ?? $platform),
            'conversation_url' => (string) ($candidate['conversation_url'] ?? ($profile?->dm_link ?: $profile?->profile_url ?: '')),
            'waiting_until' => null,
        ]);
    }

    private function candidateArray(
        CreatorProfile $profile,
        string $taskType,
        string $priority,
        Carbon $dueAt,
        string $actionableChannel,
        string $conversationUrl,
        string $notes,
        array $metadata = []
    ): array {
        $urgencyBonus = $dueAt->lessThanOrEqualTo(now()) ? 20 : 0;
        $valueScore = (int) ($profile->value_score ?? 0);

        return [
            'profile' => $profile,
            'task_type' => $taskType,
            'priority' => strtoupper($priority),
            'due_at' => $dueAt,
            'actionable_channel' => $actionableChannel,
            'conversation_url' => $conversationUrl,
            'notes' => $notes,
            'metadata' => $metadata,
            'rank_score' => $valueScore + $urgencyBonus + $this->priorityWeight(strtoupper($priority)),
        ];
    }

    private function findNewestOpenTaskForProfile(string $projectId, string $profileId, string $excludeTaskId): ?array
    {
        $task = Task::query()
            ->where('project_id', $projectId)
            ->where('creator_profile_id', $profileId)
            ->where('id', '!=', $excludeTaskId)
            ->whereIn('status', self::OPEN_TASK_STATUSES)
            ->latest('created_at')
            ->with('creatorProfile')
            ->first();

        return $task ? $this->normalizeDbTask($task) : null;
    }

    private function resolveTaskSettings(?Workspace $workspace): array
    {
        $defaults = $this->defaultTaskSettings();
        $settings = (array) ($workspace?->settings ?? []);
        $taskSettings = (array) ($settings['taskAutomation'] ?? []);

        return $this->sanitizeTaskSettings(array_replace_recursive($defaults, $taskSettings));
    }

    private function defaultTaskSettings(): array
    {
        return [
            'version' => 1,
            'settings_edit_scope' => 'admins',
            'daily_outreach_capacity' => 12,
            'max_active_tasks' => 18,
            'time_pressure_active_task_limit' => 32,
            'max_new_tasks_per_generation' => 12,
            'follow_up_delay_days' => 5,
            'aggressive_follow_up_delay_days' => 2,
            'reply_check_in_delay_days' => 2,
            'external_check_in_days' => 3,
            'max_follow_up_attempts' => 2,
            'high_value_warmup_enabled' => true,
            'high_value_threshold' => 75,
            'medium_value_threshold' => 50,
            'warmup_gap_hours' => 12,
            'time_pressure_mode' => false,
            'archive_snooze_days' => 30,
        ];
    }

    private function sanitizeTaskSettings(array $settings): array
    {
        $defaults = $this->defaultTaskSettings();
        $merged = array_replace_recursive($defaults, $settings);

        $ints = [
            'version',
            'daily_outreach_capacity',
            'max_active_tasks',
            'time_pressure_active_task_limit',
            'max_new_tasks_per_generation',
            'follow_up_delay_days',
            'aggressive_follow_up_delay_days',
            'reply_check_in_delay_days',
            'external_check_in_days',
            'max_follow_up_attempts',
            'high_value_threshold',
            'medium_value_threshold',
            'warmup_gap_hours',
            'archive_snooze_days',
        ];
        foreach ($ints as $key) {
            $merged[$key] = max(0, (int) ($merged[$key] ?? $defaults[$key]));
        }

        $merged['high_value_warmup_enabled'] = (bool) ($merged['high_value_warmup_enabled'] ?? $defaults['high_value_warmup_enabled']);
        $merged['time_pressure_mode'] = (bool) ($merged['time_pressure_mode'] ?? $defaults['time_pressure_mode']);
        $merged['settings_edit_scope'] = in_array(($merged['settings_edit_scope'] ?? 'admins'), ['admins', 'all_seats'], true)
            ? $merged['settings_edit_scope']
            : 'admins';

        return $merged;
    }

    private function canEditTaskSettings(array $settings, ?string $role): bool
    {
        $role = strtolower(trim((string) $role));
        if ($role === 'owner' || $role === 'admin') {
            return true;
        }

        return ($settings['settings_edit_scope'] ?? 'admins') === 'all_seats' && $role === 'member';
    }

    private function resolveWorkspaceForSheet(string $sheetId, $project = null): ?Workspace
    {
        $workspace = request()?->attributes->get('workspace');
        if ($workspace instanceof Workspace) {
            return $workspace;
        }

        $workspaceId = trim((string) request()?->attributes->get('workspace_id'));
        if ($workspaceId !== '') {
            return Workspace::query()->find($workspaceId);
        }

        if ($project && !empty($project->workspace_id)) {
            return Workspace::query()->find($project->workspace_id);
        }

        $resolved = $this->projects->findByWorkbookId($sheetId);
        if ($resolved && !empty($resolved->workspace_id)) {
            return Workspace::query()->find($resolved->workspace_id);
        }

        return null;
    }

    private function currentWorkspaceRole(): ?string
    {
        $role = request()?->attributes->get('workspace_role');
        $role = is_string($role) ? trim($role) : '';

        return $role !== '' ? $role : null;
    }

    private function timePressureEnabled(array $settings): bool
    {
        return (bool) ($settings['time_pressure_mode'] ?? false);
    }

    private function markExternalConversationActive(CreatorProfile $profile, array &$state, array $settings, string $externalChannel, string $conversationUrl): void
    {
        $nextCheck = now()->addDays((int) ($settings['external_check_in_days'] ?? 3));
        $state['external_conversation_active'] = true;
        $state['external_channel'] = $externalChannel !== '' ? $externalChannel : (string) ($profile->conversation_channel ?: $profile->platform ?: 'instagram');
        $state['external_conversation_url'] = $conversationUrl !== '' ? $conversationUrl : (string) ($profile->conversation_url ?: $profile->dm_link ?: $profile->profile_url ?: '');
        $state['external_check_in_at'] = $nextCheck->toIso8601String();

        $profile->conversation_channel = $state['external_channel'];
        $profile->conversation_url = $state['external_conversation_url'];
        $profile->waiting_until = $nextCheck;
        $profile->next_action_at = $nextCheck;
        $profile->follow_up_due_at = null;
        $profile->follow_up_needed = false;
    }

    private function profileAutomationState(CreatorProfile $profile): array
    {
        return is_array($profile->automation_state) ? $profile->automation_state : [];
    }

    private function priorityFromProfile(CreatorProfile $profile, bool $boostUrgency = false): string
    {
        $score = (int) ($profile->value_score ?? 0);
        $priority = match ($this->scoring->tier($score)) {
            'HIGH' => 'HIGH',
            'MEDIUM' => 'MEDIUM',
            default => 'LOW',
        };

        if ($boostUrgency && $priority !== 'URGENT') {
            return $priority === 'HIGH' ? 'URGENT' : 'HIGH';
        }

        return $priority;
    }

    private function priorityWeight(string $priority): int
    {
        return match ($priority) {
            'URGENT' => 40,
            'HIGH' => 25,
            'MEDIUM' => 15,
            default => 5,
        };
    }

    private function groupMetaForTask(string $taskType, array $metadata = [], bool $manual = false): array
    {
        if ($manual) {
            return [
                'group_key' => 'manual:' . strtolower($taskType),
                'group_label' => 'Manual tasks',
                'group_type' => 'manual',
            ];
        }

        return match ($taskType) {
            'FOLLOW_REQUEST', 'COMMENT_ON_POST' => !empty($metadata['group_context']) && $metadata['group_context'] === 'follow_up' ? [
                'group_key' => 'follow-up-soft-touch:' . strtolower($taskType),
                'group_label' => 'Use softer follow-ups on no-reply creators',
                'group_type' => 'follow_up',
            ] : [
                'group_key' => 'warmup:' . strtolower($taskType),
                'group_label' => 'Warm high-value creators',
                'group_type' => 'warmup',
            ],
            'DM_INVITE', 'EMAIL_SEND' => [
                'group_key' => 'first-outreach:' . strtolower($taskType),
                'group_label' => 'Send first outreach',
                'group_type' => 'first_outreach',
            ],
            'DM_FOLLOWUP' => [
                'group_key' => 'follow-up',
                'group_label' => 'Follow up on no-reply creators',
                'group_type' => 'follow_up',
            ],
            'REVIEW_CREATOR' => [
                'group_key' => 'reply-review',
                'group_label' => 'Review engaged creators',
                'group_type' => 'reply_review',
            ],
            'CHECK_IN' => [
                'group_key' => 'check-in:' . strtolower((string) ($metadata['group_context'] ?? 'general')),
                'group_label' => !empty($metadata['group_context']) && $metadata['group_context'] === 'external_conversation'
                    ? 'Check outside-app conversations'
                    : 'Check creator progress',
                'group_type' => 'check_in',
            ],
            'NEGOTIATE_TERMS' => [
                'group_key' => 'negotiation',
                'group_label' => 'Advance active conversations',
                'group_type' => 'negotiation',
            ],
            'ARCHIVE_CREATOR' => [
                'group_key' => 'cleanup',
                'group_label' => 'Clean up stalled creators',
                'group_type' => 'cleanup',
            ],
            default => [
                'group_key' => 'general:' . strtolower($taskType),
                'group_label' => 'Task queue',
                'group_type' => 'general',
            ],
        };
    }

    private function defaultNotesForTaskType(string $taskType): string
    {
        return match ($taskType) {
            'FOLLOW_REQUEST' => 'Follow this creator to start warming the relationship.',
            'COMMENT_ON_POST' => 'Leave a natural comment or warm interaction before pushing the conversation forward.',
            'DM_FOLLOWUP' => 'Take the next platform-safe follow-up step for this creator. On Instagram or TikTok that often means a soft public touch, not another cold DM.',
            'EMAIL_SEND' => 'Use email for this outreach because contact data exists.',
            'REVIEW_CREATOR' => 'Review the conversation and decide the next move.',
            'CHECK_IN' => 'Check where the conversation currently lives and log what happened.',
            default => 'This creator is ready for the next outreach step.',
        };
    }

    private function pickTemplateFromDatabase(array $templates, string $platform, string $taskType): ?MessageTemplate
    {
        if ($templates === []) {
            return null;
        }

        $targetPlatform = $taskType === 'EMAIL_SEND' ? 'email' : strtolower(trim($platform));
        $targetStage = $this->stageFromTaskType($taskType);

        usort($templates, function (MessageTemplate $a, MessageTemplate $b) use ($targetPlatform, $targetStage) {
            $score = function (MessageTemplate $template) use ($targetPlatform, $targetStage): int {
                $value = 0;
                if (strtolower(trim((string) ($template->platform ?: ''))) === $targetPlatform) {
                    $value += 4;
                }
                if (strtolower(trim((string) ($template->stage ?: 'cold_invite'))) === $targetStage) {
                    $value += 3;
                }
                return $value;
            };
            return $score($b) <=> $score($a);
        });

        return $templates[0] ?? null;
    }

    private function stageFromTaskType(string $taskType): string
    {
        return match ($taskType) {
            'DM_FOLLOWUP' => 'follow_up',
            'NEGOTIATE_TERMS' => 'negotiation',
            'CHECK_IN' => 'check_in',
            'CONFIRM_ACCEPTED' => 'after_accept',
            default => 'cold_invite',
        };
    }

    private function buildMessageDraftFromProfile(?MessageTemplate $template, CreatorProfile $profile, string $taskType): string
    {
        $base = (string) ($template?->copy ?: $this->defaultMessageBase($taskType));
        $handle = ltrim((string) ($profile->handle ?: ''), '@');
        $name = (string) (optional($profile->creator)->display_name ?: 'there');

        return str_replace(['{{handle}}', '{{name}}'], [$handle, $name], $base);
    }

    private function defaultMessageBase(string $taskType): string
    {
        return match ($taskType) {
            'EMAIL_SEND' => 'Hey {{name}}, I think your content could be a strong fit for a collaboration.',
            'DM_FOLLOWUP' => 'Hey {{handle}}, just following up in case my first message got buried.',
            'COMMENT_ON_POST' => 'Love this post. The framing is strong.',
            'NEGOTIATE_TERMS' => 'Hey {{handle}}, excited to move this forward. Here are the details.',
            'CHECK_IN' => 'Quick check-in: where does the conversation stand right now?',
            default => 'Hey {{handle}}, loved your content and think there could be a good fit here.',
        };
    }

    private function normalizeDbTask(Task $task): array
    {
        $groupMeta = $this->groupMetaForTask((string) $task->task_type, (array) ($task->metadata ?? []));

        return [
            'taskId' => (string) ($task->external_task_key ?: $task->id),
            'taskType' => (string) $task->task_type,
            'platform' => strtolower((string) ($task->platform ?: 'instagram')),
            'handle' => (string) ($task->handle ?: ''),
            'profileUrl' => (string) ($task->open_url ?: ''),
            'dmUrl' => (string) ($task->open_url ?: ''),
            'status' => $this->normalizeStatus(strtoupper((string) ($task->status ?: 'PENDING'))),
            'priority' => $this->normalizePriority(strtoupper((string) ($task->priority ?: 'LOW'))),
            'dueDate' => optional($task->due_at)?->toIso8601String() ?? '',
            'createdAt' => optional($task->created_at)?->toIso8601String() ?? '',
            'completedAt' => optional($task->completed_at)?->toIso8601String() ?? '',
            'snoozedUntil' => optional($task->snoozed_until)?->toIso8601String(),
            'messageText' => (string) ($task->message_draft ?: ''),
            'notes' => (string) ($task->notes ?: ''),
            'followUpCount' => (int) ($task->follow_up_count ?? 0),
            'platformConnectionState' => (string) ($task->platform_connection_state ?: 'none'),
            'profilePicUrl' => (string) ($task->creatorProfile?->profile_pic_url ?: ''),
            'groupKey' => (string) ($task->group_key ?: $groupMeta['group_key']),
            'groupLabel' => (string) ($task->group_label ?: $groupMeta['group_label']),
            'groupType' => (string) ($task->group_type ?: $groupMeta['group_type']),
            'completionOutcome' => (string) ($task->completion_outcome ?: ''),
            'skipReason' => (string) ($task->skip_reason ?: ''),
            'skipReasonDetail' => (string) ($task->skip_reason_detail ?: ''),
            'actionableChannel' => (string) ($task->actionable_channel ?: $task->platform ?: ''),
            'externalChannel' => (string) ($task->external_channel ?: $task->creatorProfile?->conversation_channel ?: ''),
            'conversationUrl' => (string) ($task->conversation_url ?: $task->creatorProfile?->conversation_url ?: $task->open_url ?: ''),
            'creatorProfileId' => (string) ($task->creator_profile_id ?: ''),
            'valueScore' => (int) ($task->creatorProfile?->value_score ?? 0),
            'email' => (string) ($task->creatorProfile?->creator?->primary_email ?: ''),
            'lastOutreachAt' => optional($task->creatorProfile?->last_outreach_at)?->toIso8601String() ?? '',
            'metadata' => array_merge((array) ($task->metadata ?? []), [
                'last_outreach_at' => optional($task->creatorProfile?->last_outreach_at)?->toIso8601String() ?? null,
            ]),
        ];
    }

    private function normalizeSheetTaskRow(array $row): array
    {
        $taskType = (string) ($row['Task_Type'] ?? 'DM_INVITE');
        $groupMeta = $this->groupMetaForTask($taskType, []);

        return [
            'taskId' => (string) ($row['Task_ID'] ?? ''),
            'taskType' => $taskType,
            'platform' => strtolower((string) ($row['Platform'] ?? 'instagram')),
            'handle' => (string) ($row['Handle'] ?? ''),
            'profileUrl' => (string) ($row['Open_URL'] ?? ''),
            'dmUrl' => (string) ($row['Open_URL'] ?? ''),
            'status' => $this->normalizeStatus(strtoupper((string) ($row['Status'] ?? 'PENDING'))),
            'priority' => $this->normalizePriority(strtoupper((string) ($row['Priority'] ?? 'LOW'))),
            'dueDate' => (string) ($row['Due_At'] ?? ''),
            'createdAt' => (string) ($row['Created_At'] ?? ''),
            'completedAt' => (string) ($row['Completed_At'] ?? ''),
            'snoozedUntil' => null,
            'messageText' => (string) ($row['Message_Draft'] ?? ''),
            'notes' => (string) ($row['Notes'] ?? ''),
            'followUpCount' => 0,
            'platformConnectionState' => 'none',
            'profilePicUrl' => '',
            'groupKey' => $groupMeta['group_key'],
            'groupLabel' => $groupMeta['group_label'],
            'groupType' => $groupMeta['group_type'],
            'completionOutcome' => '',
            'skipReason' => '',
            'skipReasonDetail' => '',
            'actionableChannel' => strtolower((string) ($row['Platform'] ?? 'instagram')),
            'externalChannel' => '',
            'conversationUrl' => (string) ($row['Open_URL'] ?? ''),
            'creatorProfileId' => '',
            'valueScore' => 0,
            'email' => '',
            'lastOutreachAt' => '',
            'metadata' => [],
        ];
    }

    private function generateSheetFallbackTasks(string $sheetId, array $options = []): array
    {
        $limit = max(1, (int) ($options['limit'] ?? 20));
        $crmRows = $this->sheets->getRows($sheetId, 'Creators_CRM');
        $messageLibrary = $this->sheets->getRows($sheetId, 'Message_Library');
        $existing = $this->sheets->getRows($sheetId, 'Task_Queue');
        $headers = $this->sheets->getHeaders($sheetId, 'Task_Queue');

        $openKeys = [];
        foreach ($existing as $task) {
            if (!in_array(strtoupper((string) ($task['Status'] ?? 'PENDING')), self::TERMINAL_TASK_STATUSES, true)) {
                $openKeys[$this->taskUniqKey((string) ($task['Platform'] ?? ''), (string) ($task['Handle'] ?? ''), (string) ($task['Task_Type'] ?? ''))] = true;
            }
        }

        $records = [];
        $created = 0;
        $eligible = 0;
        $skippedExisting = 0;
        $skippedIneligible = 0;

        foreach ($crmRows as $creator) {
            if ($created >= $limit) {
                break;
            }

            $taskType = $this->determineInitialTaskTypeFromRow($creator);
            if (!$taskType) {
                $skippedIneligible++;
                continue;
            }

            $eligible++;
            $uniqKey = $this->taskUniqKey((string) ($creator['Platform'] ?? ''), (string) ($creator['Handle'] ?? ''), $taskType);
            if (isset($openKeys[$uniqKey])) {
                $skippedExisting++;
                continue;
            }

            $taskId = (string) Str::uuid();
            $records[] = [
                'Task_ID' => $taskId,
                'Platform' => (string) ($creator['Platform'] ?? ''),
                'Handle' => (string) ($creator['Handle'] ?? ''),
                'Task_Type' => $taskType,
                'Priority' => 'MEDIUM',
                'Status' => 'PENDING',
                'Due_At' => now()->toDateTimeString(),
                'Open_URL' => (string) ($creator['DM_Link'] ?? ''),
                'Message_Draft' => (string) (($messageLibrary[0]['DM_Template'] ?? 'Hey {{handle}}, loved your content.')),
                'Template_ID' => '',
                'Created_At' => now()->toDateTimeString(),
                'Completed_At' => '',
                'Notes' => 'Auto-generated',
            ];
            $created++;
            $openKeys[$uniqKey] = true;
        }

        if ($records !== []) {
            $this->sheets->appendAssocRows($sheetId, 'Task_Queue', $records, $headers);
        }

        return [
            'created' => $created,
            'eligible' => $eligible,
            'skipped_existing' => $skippedExisting,
            'skipped_ineligible' => $skippedIneligible,
            'taskSheet' => 'Task_Queue',
            'source' => 'google_sheets',
        ];
    }

    private function determineInitialTaskTypeFromRow(array $creator): ?string
    {
        $platform = strtolower(trim((string) ($creator['Platform'] ?? '')));
        $hasEmail = trim((string) ($creator['Contact_Email'] ?? '')) !== '';
        $preferredChannel = strtoupper(trim((string) ($creator['Preferred_Channel'] ?? 'DM')));

        if ($preferredChannel === 'EMAIL' && $hasEmail) {
            return 'EMAIL_SEND';
        }

        if (in_array($platform, self::REQUIRES_CONNECTION, true)) {
            return 'FOLLOW_REQUEST';
        }

        return $platform !== '' ? 'DM_INVITE' : null;
    }

    private function initialConnectionState(string $platform, ?CreatorProfile $profile): string
    {
        if (!in_array($platform, self::REQUIRES_CONNECTION, true)) {
            return 'none';
        }

        if (!$profile) {
            return 'none';
        }

        return match (strtoupper((string) ($profile->status ?? ''))) {
            'FOLLOW_REQUEST_SENT' => 'pending',
            'CONTACTED', 'FOLLOWED_UP', 'NEGOTIATING', 'ACCEPTED', 'ACTIVE', 'POSTED', 'REPLIED' => 'connected',
            default => 'none',
        };
    }

    private function taskUniqKey(string $platform, string $handle, string $taskType): string
    {
        return strtolower(trim($platform)) . '|' . strtolower(trim($handle)) . '|' . strtoupper(trim($taskType));
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'DONE', 'COMPLETED' => 'completed',
            'SKIPPED' => 'skipped',
            'IN_PROGRESS' => 'in_progress',
            'SNOOZED' => 'snoozed',
            'ARCHIVED' => 'archived',
            default => 'pending',
        };
    }

    private function normalizePriority(string $priority): string
    {
        return match ($priority) {
            'URGENT' => 'urgent',
            'HIGH' => 'high',
            'MEDIUM' => 'medium',
            default => 'low',
        };
    }

    private function eventTypeFromTask(string $taskType, string $status = 'COMPLETED', string $context = ''): string
    {
        $status = strtoupper(trim($status));
        if ($status === 'SNOOZED') {
            return 'TASK_SNOOZED';
        }
        if ($status === 'SKIPPED') {
            return 'TASK_SKIPPED';
        }

        return match ($taskType) {
            'FOLLOW_REQUEST' => 'FOLLOW_SENT_CONFIRMED',
            'DM_INVITE' => $context === 'creator_replied' ? 'DM_REPLY_RECEIVED' : 'DM_SENT_CONFIRMED',
            'DM_FOLLOWUP' => $context === 'creator_replied' ? 'FOLLOWUP_REPLY_RECEIVED' : 'FOLLOWUP_SENT_CONFIRMED',
            'EMAIL_SEND' => $context === 'creator_replied' ? 'EMAIL_REPLY_RECEIVED' : 'EMAIL_SENT',
            'COMMENT_ON_POST' => 'COMMENT_POSTED',
            'REVIEW_CREATOR' => 'CREATOR_REVIEWED',
            'CHECK_IN' => 'CHECK_IN_LOGGED',
            'NEGOTIATE_TERMS' => 'TERMS_SENT',
            'CONFIRM_ACCEPTED' => 'FOLLOW_ACCEPTED_CONFIRMED',
            'ARCHIVE_CREATOR' => 'CREATOR_ARCHIVED',
            default => 'TASK_COMPLETED',
        };
    }

    private function coerceCarbon(mixed $value): ?Carbon
    {
        try {
            if ($value instanceof Carbon) {
                return $value;
            }
            if (is_string($value) && trim($value) !== '') {
                return Carbon::parse($value);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to coerce date', ['value' => $value, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
