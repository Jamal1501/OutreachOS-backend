<?php

namespace App\Services;

use App\Models\CreatorProfile;
use App\Models\MessageTemplate;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class TaskQueueService
{
    // ─── Platform rules ───────────────────────────────────────────────────────

    /** Max outreach attempts before a creator is considered non-responsive. */
    private const PLATFORM_DM_LIMITS = [
        'instagram' => 2,
        'tiktok'    => 2,
        'email'     => 2,
    ];

    /** Platforms that require a follow/connection before DM is possible. */
    private const REQUIRES_CONNECTION = ['instagram'];

    // ─── Constructor ─────────────────────────────────────────────────────────

    public function __construct(
        private GoogleSheetsService $sheets,
        private OutreachLogService $outreachLog,
        private InfluencerScoringService $scoring,
        private OperationalMirrorService $mirror,
        private ProjectResolverService $projects,
    ) {
    }

    // ─── Public: list ─────────────────────────────────────────────────────────

    public function listTasks(string $sheetId): array
    {
        if ($this->mirror->enabled()) {
            $dbTasks = $this->listTasksFromDatabase($sheetId);
            if ($dbTasks !== null) {
                return $dbTasks;
            }
        }

        if (str_starts_with($sheetId, 'workspace:')) {
            return [];
        }

        $rows = $this->sheets->getRows($sheetId, 'Task_Queue');

        // Sort: priority desc, then created_at desc (sheets have no due_at sort index).
        $priorityOrder = ['URGENT' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3];
        usort($rows, function (array $a, array $b) use ($priorityOrder) {
            $pa = $priorityOrder[strtoupper((string) ($a['Priority'] ?? ''))] ?? 9;
            $pb = $priorityOrder[strtoupper((string) ($b['Priority'] ?? ''))] ?? 9;
            if ($pa !== $pb) return $pa <=> $pb;
            return strcmp((string) ($b['Created_At'] ?? ''), (string) ($a['Created_At'] ?? ''));
        });

        return array_map(fn (array $row) => $this->normalizeSheetTaskRow($row), $rows);
    }

    public function listColdRetry(string $sheetId): array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            return [];
        }

        $tasks = Task::query()
            ->where('tasks.project_id', $project->id)
            ->coldRetry()
            ->get();

        return $tasks->map(function (Task $task) {
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
        })->values()->all();
    }

    // ─── Public: snooze ───────────────────────────────────────────────────────

    /**
     * Snooze a task until $until. Re-surfaces automatically via scopeVisible()
     * when snoozed_until <= now().
     */
    public function snoozeTask(string $sheetId, string $taskId, Carbon $until): array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            throw new RuntimeException('Project not found for sheet: ' . $sheetId);
        }

        $task = Task::query()
            ->where('project_id', $project->id)
            ->where(fn ($q) => $q->where('external_task_key', $taskId)->orWhere('id', $taskId))
            ->firstOrFail();

        $task->status        = 'SNOOZED';
        $task->snoozed_until = $until;
        $task->save();

        return [
            'taskId'       => (string) ($task->external_task_key ?: $task->id),
            'status'       => 'SNOOZED',
            'snoozedUntil' => $until->toIso8601String(),
        ];
    }

    // ─── Public: generate ────────────────────────────────────────────────────

    public function generateInitialTasks(string $sheetId, array $options = []): array
    {
        if ($this->mirror->enabled()) {
            $dbResult = $this->generateInitialTasksFromDatabase($sheetId, $options);
            if ($dbResult !== null) {
                return $dbResult;
            }
        }

        $limit            = max(1, (int) ($options['limit'] ?? 50));
        $crmRows          = $this->sheets->getRows($sheetId, 'Creators_CRM');
        $targetRowNumbers = array_key_exists('rowNumbers', $options)
            ? array_values(array_unique(array_filter(array_map('intval', (array) ($options['rowNumbers'] ?? [])), fn (int $n) => $n > 1)))
            : null;

        if (is_array($targetRowNumbers)) {
            $rowLookup = array_fill_keys($targetRowNumbers, true);
            $crmRows   = array_values(array_filter($crmRows, fn (array $c) => isset($rowLookup[(int) ($c['_row_number'] ?? 0)])));
            usort($crmRows, fn ($a, $b) => ((int) ($a['_row_number'] ?? 0)) <=> ((int) ($b['_row_number'] ?? 0)));
        }

        $taskHeaders    = $this->sheets->getHeaders($sheetId, 'Task_Queue');
        $openTasks      = $this->sheets->getRows($sheetId, 'Task_Queue');
        $messageLibrary = $this->sheets->getRows($sheetId, 'Message_Library');

        $existingTaskKeys = [];
        foreach ($openTasks as $task) {
            $status = strtoupper((string) ($task['Status'] ?? ''));
            if (!in_array($status, ['DONE', 'COMPLETED', 'SKIPPED', 'ARCHIVED'], true)) {
                $existingTaskKeys[$this->taskUniqKey($task['Platform'] ?? '', $task['Handle'] ?? '', $task['Task_Type'] ?? '')] = true;
            }
        }

        $recordsToAppend = [];
        $logEvents       = [];
        $created = $eligible = $skippedExisting = $skippedIneligible = 0;

        foreach ($crmRows as $creator) {
            if ($created >= $limit) {
                break;
            }

            $taskType = $this->determineInitialTaskType($creator);
            if ($taskType === null) {
                $skippedIneligible++;
                continue;
            }

            $eligible++;
            $taskKey = $this->taskUniqKey($creator['Platform'] ?? '', $creator['Handle'] ?? '', $taskType);
            if (isset($existingTaskKeys[$taskKey])) {
                $skippedExisting++;
                continue;
            }

            $template     = $this->pickTemplate($messageLibrary, (string) ($creator['Platform'] ?? ''), $taskType);
            $taskId       = (string) Str::uuid();
            $openUrl      = (string) ($creator['DM_Link'] ?? '');
            $messageDraft = $this->buildMessageDraft($template, $creator, $taskType);

            $record = [
                'Task_ID'       => $taskId,
                'Platform'      => (string) ($creator['Platform'] ?? ''),
                'Handle'        => (string) ($creator['Handle'] ?? ''),
                'Task_Type'     => $taskType,
                'Priority'      => $this->priorityFromCreator($creator),
                'Status'        => 'PENDING',
                'Due_At'        => now()->toDateTimeString(),
                'Open_URL'      => $openUrl,
                'Message_Draft' => $messageDraft,
                'Template_ID'   => (string) ($template['Angle_Name'] ?? ''),
                'Created_At'    => now()->toDateTimeString(),
                'Completed_At'  => '',
                'Notes'         => 'Auto-generated from Creators_CRM',
            ];

            $recordsToAppend[] = $record;
            $logEvents[]       = [
                'Task_ID'     => $taskId,
                'Platform'    => $record['Platform'],
                'Handle'      => $record['Handle'],
                'Channel'     => $this->channelFromTaskType($taskType, $creator),
                'Event_Type'  => 'TASK_CREATED',
                'Template_ID' => $record['Template_ID'],
                'Status'      => 'PENDING',
                'URL'         => $openUrl,
                'Notes'       => $taskType,
            ];
            $existingTaskKeys[$taskKey] = true;
            $created++;
        }

        if ($recordsToAppend !== []) {
            $this->sheets->appendAssocRows($sheetId, 'Task_Queue', $recordsToAppend, $taskHeaders);
            $this->mirror->syncTasks($sheetId, array_map(fn ($r) => (string) $r['Task_ID'], $recordsToAppend));
        }

        if ($logEvents !== []) {
            $this->outreachLog->appendEvents($sheetId, $logEvents);
        }

        return [
            'created'           => $created,
            'eligible'          => $eligible,
            'skipped_existing'  => $skippedExisting,
            'skipped_ineligible' => $skippedIneligible,
            'taskSheet'         => 'Task_Queue',
            'sourceRowNumbers'  => $targetRowNumbers,
            'source'            => 'google_sheets',
        ];
    }

    // ─── Public: complete ────────────────────────────────────────────────────

    public function completeTask(string $sheetId, string $taskId, array $payload = []): array
    {
        if ($this->mirror->enabled()) {
            $dbResult = $this->completeTaskInDatabase($sheetId, $taskId, $payload);
            if ($dbResult !== null) {
                return $dbResult;
            }
        }

        $task = $this->sheets->findFirstRowBy($sheetId, 'Task_Queue', 'Task_ID', $taskId);
        if (!$task) {
            throw new RuntimeException('Task not found');
        }

        if (array_key_exists('template_id', $payload) && $payload['template_id'] !== null) {
            $task['Template_ID'] = (string) $payload['template_id'];
        }
        if (array_key_exists('message_draft', $payload) && $payload['message_draft'] !== null) {
            $task['Message_Draft'] = (string) $payload['message_draft'];
        }

        $status        = strtoupper(trim((string) ($payload['status'] ?? 'COMPLETED')));
        $existingNotes = trim((string) ($task['Notes'] ?? ''));
        $newNotes      = trim((string) ($payload['notes'] ?? ''));

        $task['Status']       = $status;
        $task['Completed_At'] = in_array($status, ['COMPLETED', 'DONE', 'SKIPPED'], true) ? now()->toDateTimeString() : '';
        $task['Notes']        = trim($existingNotes . ($newNotes !== '' ? ' ' . $newNotes : ''));

        $this->sheets->updateAssocRow($sheetId, 'Task_Queue', (int) $task['_row_number'], $task);

        $creator = $this->findCreator($sheetId, (string) ($task['Platform'] ?? ''), (string) ($task['Handle'] ?? ''));
        if ($creator && in_array($status, ['COMPLETED', 'DONE'], true)) {
            $creator = $this->applyTaskToCreator($creator, $task);
            $this->sheets->updateAssocRow($sheetId, 'Creators_CRM', (int) $creator['_row_number'], $creator);
            $this->mirror->syncCreators($sheetId, [(int) ($creator['_row_number'] ?? 0)]);
        }

        $this->mirror->syncTasks($sheetId, [$taskId]);

        $eventId = $this->outreachLog->appendEvent($sheetId, [
            'Task_ID'        => $taskId,
            'Platform'       => (string) ($task['Platform'] ?? ''),
            'Handle'         => (string) ($task['Handle'] ?? ''),
            'Channel'        => $this->channelFromTaskType((string) ($task['Task_Type'] ?? ''), $creator ?? []),
            'Event_Type'     => $this->eventTypeFromTask((string) ($task['Task_Type'] ?? ''), $status),
            'Template_ID'    => (string) ($task['Template_ID'] ?? ''),
            'Sender_Account' => (string) ($payload['sender_account'] ?? ''),
            'Status'         => $status,
            'URL'            => (string) ($task['Open_URL'] ?? ''),
            'Notes'          => (string) ($payload['notes'] ?? ''),
        ]);

        return [
            'taskId'  => $taskId,
            'eventId' => $eventId,
            'status'  => $task['Status'],
            'source'  => 'google_sheets',
        ];
    }

    // ─── Private: database complete ──────────────────────────────────────────

    private function completeTaskInDatabase(string $sheetId, string $taskId, array $payload = []): ?array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            return null;
        }

        $task = Task::query()
            ->where('project_id', $project->id)
            ->where(fn ($q) => $q->where('external_task_key', $taskId)->orWhere('id', $taskId))
            ->with(['creatorProfile.creator', 'messageTemplate'])
            ->first();

        if (!$task) {
            return null;
        }

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

        $status        = strtoupper(trim((string) ($payload['status'] ?? 'COMPLETED')));
        $existingNotes = trim((string) ($task->notes ?? ''));
        $newNotes      = trim((string) ($payload['notes'] ?? ''));

        // Track follow-up attempts so platform limits can be enforced.
        if (in_array($task->task_type, ['DM_FOLLOWUP', 'EMAIL_SEND', 'DM_INVITE'], true) && $status === 'COMPLETED') {
            $task->follow_up_count = ((int) ($task->follow_up_count ?? 0)) + 1;
        }

        $task->status       = $status;
        $task->completed_at = in_array($status, ['COMPLETED', 'DONE', 'SKIPPED'], true) ? now() : null;
        $task->notes        = trim($existingNotes . ($newNotes !== '' ? ' ' . $newNotes : ''));
        $task->save();

        $profile = $task->creatorProfile;
        if ($profile && in_array($status, ['COMPLETED', 'DONE'], true)) {
            $this->applyTaskToCreatorProfile($profile, $task);
            $profile->save();
            $this->maybeGenerateNextTask($project->id, $profile, $task);
        }

        $sheetSync = ['task' => false, 'creator' => false];

        $taskRowNumber = $this->extractSheetRowNumber((string) ($task->source_reference ?? ''), 'Task_Queue');
        if ($taskRowNumber > 0) {
            try {
                $taskRow = $this->sheets->findFirstRowBy($sheetId, 'Task_Queue', 'Task_ID', (string) ($task->external_task_key ?: $taskId));
                if ($taskRow) {
                    if ($task->messageTemplate) {
                        $taskRow['Template_ID'] = (string) ($task->messageTemplate->angle_id ?: '');
                    }
                    $taskRow['Message_Draft'] = (string) ($task->message_draft ?? '');
                    $taskRow['Status']        = $status;
                    $taskRow['Completed_At']  = $task->completed_at?->toDateTimeString() ?? '';
                    $taskRow['Notes']         = (string) ($task->notes ?? '');
                    $this->sheets->updateAssocRow($sheetId, 'Task_Queue', (int) $taskRow['_row_number'], $taskRow);
                    $sheetSync['task'] = true;
                }
            } catch (\Throwable $e) {
                Log::warning('Task_Queue sheet sync failed after database task completion', [
                    'sheet_id' => $sheetId, 'task_id' => $taskId, 'error' => $e->getMessage(),
                ]);
            }
        }

        if ($profile && in_array($status, ['COMPLETED', 'DONE'], true)) {
            $creatorRowNumber = $this->extractSheetRowNumber((string) ($profile->source_reference ?? ''), 'Creators_CRM');
            if ($creatorRowNumber > 0) {
                try {
                    $creator = $this->findCreator($sheetId, (string) ($profile->platform ?? ''), (string) ($profile->handle ?? ''));
                    if ($creator) {
                        $creator = $this->applyTaskToCreator($creator, ['Task_Type' => (string) $task->task_type]);
                        $this->sheets->updateAssocRow($sheetId, 'Creators_CRM', (int) $creator['_row_number'], $creator);
                        $sheetSync['creator'] = true;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Creators_CRM sheet sync failed after database task completion', [
                        'sheet_id' => $sheetId, 'task_id' => $taskId, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $eventId = $this->outreachLog->appendEvent($sheetId, [
            'Task_ID'            => (string) ($task->external_task_key ?: $task->id),
            'creator_profile_id' => $profile?->id,
            'Platform'           => (string) ($task->platform ?? ''),
            'Handle'             => (string) ($task->handle ?? ''),
            'Channel'            => $this->channelFromTaskType((string) $task->task_type, [
                'Platform'          => (string) ($task->platform ?? ''),
                'Preferred_Channel' => (string) ($profile?->preferred_channel ?? ''),
            ]),
            'Event_Type'         => $this->eventTypeFromTask((string) $task->task_type, $status),
            'Template_ID'        => (string) ($task->messageTemplate?->angle_id ?: ''),
            'Sender_Account'     => (string) ($payload['sender_account'] ?? ''),
            'Status'             => $status,
            'URL'                => (string) ($task->open_url ?? ''),
            'Notes'              => (string) ($payload['notes'] ?? ''),
        ]);

        return [
            'taskId'    => (string) ($task->external_task_key ?: $task->id),
            'eventId'   => $eventId,
            'status'    => $status,
            'source'    => 'database',
            'sheetSync' => $sheetSync,
        ];
    }

    // ─── Private: database generate ──────────────────────────────────────────

    private function generateInitialTasksFromDatabase(string $sheetId, array $options = []): ?array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            return null;
        }

        $limit            = max(1, (int) ($options['limit'] ?? 50));
        $targetRowNumbers = array_key_exists('rowNumbers', $options)
            ? array_values(array_unique(array_filter(array_map('intval', (array) ($options['rowNumbers'] ?? [])), fn ($n) => $n > 1)))
            : null;
        $targetProfileIds = array_key_exists('profileIds', $options)
            ? array_values(array_unique(array_filter(array_map('strval', (array) ($options['profileIds'] ?? [])), fn ($id) => trim($id) !== '')))
            : null;

        $profilesQuery = CreatorProfile::query()
            ->with('creator')
            ->where('project_id', $project->id)
            ->orderBy('created_at');

        if (is_array($targetProfileIds) && $targetProfileIds !== []) {
            $profilesQuery->whereIn('id', $targetProfileIds);
        } elseif (is_array($targetRowNumbers)) {
            $profilesQuery->where(function ($q) use ($targetRowNumbers) {
                foreach ($targetRowNumbers as $rowNumber) {
                    $q->orWhere('source_reference', 'Creators_CRM:' . $rowNumber)
                      ->orWhere('source_metadata->sheet_row_number', $rowNumber);
                }
            });
        }

        $profiles = $profilesQuery->get();
        if ($profiles->isEmpty()) {
            return null;
        }

        $openTaskKeys = Task::query()
            ->where('project_id', $project->id)
            ->whereNotIn('status', ['DONE', 'COMPLETED', 'SKIPPED', 'ARCHIVED'])
            ->get(['platform', 'handle', 'task_type'])
            ->map(fn (Task $t) => $this->taskUniqKey((string) $t->platform, (string) $t->handle, (string) $t->task_type))
            ->flip()
            ->all();

        $templates = MessageTemplate::query()->where('project_id', $project->id)->get()->all();

        $logEvents = [];
        $created = $eligible = $skippedExisting = $skippedIneligible = 0;

        foreach ($profiles as $profile) {
            if ($created >= $limit) {
                break;
            }

            $taskType = $this->determineInitialTaskTypeFromProfile($profile);
            if ($taskType === null) {
                $skippedIneligible++;
                continue;
            }

            $eligible++;
            $taskKey = $this->taskUniqKey((string) $profile->platform, (string) $profile->handle, $taskType);
            if (isset($openTaskKeys[$taskKey])) {
                $skippedExisting++;
                continue;
            }

            $template     = $this->pickTemplateFromDatabase($templates, (string) $profile->platform, $taskType);
            $taskId       = (string) Str::uuid();
            $messageDraft = $this->buildMessageDraftFromProfile($template, $profile, $taskType);
            $priority     = $this->priorityFromProfile($profile);
            $openUrl      = (string) ($profile->dm_link ?: $profile->profile_url ?: '');

            Task::create([
                'project_id'                => $project->id,
                'creator_profile_id'        => $profile->id,
                'message_template_id'       => $template?->id,
                'external_task_key'         => $taskId,
                'platform'                  => strtolower((string) $profile->platform),
                'handle'                    => (string) $profile->handle,
                'task_type'                 => $taskType,
                'priority'                  => $priority,
                'status'                    => 'PENDING',
                'due_at'                    => now(),
                'open_url'                  => $openUrl,
                'message_draft'             => $messageDraft,
                'platform_connection_state' => $this->initialConnectionState((string) $profile->platform, $profile),
                'follow_up_count'           => 0,
                'source_provider'           => 'database',
                'source_reference'          => 'creator_profile:' . $profile->id,
                'notes'                     => 'Auto-generated from creator_profiles',
                'metadata'                  => [
                    'creator_profile_id'      => $profile->id,
                    'source_sheet_row_number' => $profile->source_metadata['sheet_row_number'] ?? null,
                ],
            ]);

            $logEvents[] = [
                'Task_ID'            => $taskId,
                'creator_profile_id' => $profile->id,
                'Platform'           => strtolower((string) $profile->platform),
                'Handle'             => (string) $profile->handle,
                'Channel'            => $this->channelFromTaskType($taskType, [
                    'Platform'          => strtolower((string) $profile->platform),
                    'Preferred_Channel' => (string) ($profile->preferred_channel ?: ''),
                ]),
                'Event_Type'         => 'TASK_CREATED',
                'Template_ID'        => (string) ($template?->angle_id ?: ''),
                'Status'             => 'PENDING',
                'URL'                => $openUrl,
                'Notes'              => $taskType,
            ];

            $openTaskKeys[$taskKey] = true;
            $created++;
        }

        if ($logEvents !== []) {
            $this->outreachLog->appendEvents($sheetId, $logEvents);
        }

        return [
            'created'            => $created,
            'eligible'           => $eligible,
            'skipped_existing'   => $skippedExisting,
            'skipped_ineligible' => $skippedIneligible,
            'taskSheet'          => 'Task_Queue',
            'sourceRowNumbers'   => $targetRowNumbers,
            'sourceProfileIds'   => $targetProfileIds,
            'source'             => 'database',
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
            ->with('creatorProfile')
            ->orderByRaw("CASE priority WHEN 'URGENT' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'MEDIUM' THEN 3 WHEN 'LOW' THEN 4 ELSE 5 END")
            ->orderBy('due_at')
            ->get();

        return $tasks->map(fn (Task $task) => $this->normalizeDbTask($task))->values()->all();
    }

    // ─── Next-task generation (platform-aware) ────────────────────────────────

    /**
     * After completing a task, decide if a follow-up, comment fallback,
     * or archive task should be auto-generated based on platform rules.
     */
    private function maybeGenerateNextTask(string $projectId, CreatorProfile $profile, Task $task): void
    {
        $taskType  = (string) $task->task_type;
        $platform  = strtolower((string) ($task->platform ?? ''));
        $dmLimit   = self::PLATFORM_DM_LIMITS[$platform] ?? 2;
        $followUps = (int) ($task->follow_up_count ?? 0);
        $valueTier = $this->scoring->tier((float) ($profile->value_score ?? 0));

        // Follow requests: wait for connection event — no auto next task.
        if ($taskType === 'FOLLOW_REQUEST') {
            return;
        }

        // After outreach/follow-up tasks, check if the limit has been reached.
        if (in_array($taskType, ['DM_INVITE', 'EMAIL_SEND', 'DM_FOLLOWUP'], true)) {
            if ($followUps < $dmLimit) {
                // Still within limit, no automatic follow-up — user decides.
                return;
            }

            // Limit reached. Instagram + HIGH/MEDIUM and no comment tried yet → comment fallback.
            if ($platform === 'instagram'
                && in_array($valueTier, ['HIGH', 'MEDIUM'], true)
                && $profile->comment_attempted_at === null
            ) {
                $this->createNextTask($projectId, $profile, $task, 'COMMENT_ON_POST');
                return;
            }

            // All options exhausted → archive.
            $this->createNextTask($projectId, $profile, $task, 'ARCHIVE_CREATOR');
        }
    }

    private function createNextTask(string $projectId, CreatorProfile $profile, Task $previousTask, string $taskType): void
    {
        $exists = Task::query()
            ->where('project_id', $projectId)
            ->where('creator_profile_id', $profile->id)
            ->where('task_type', $taskType)
            ->whereNotIn('status', ['COMPLETED', 'DONE', 'SKIPPED', 'ARCHIVED'])
            ->exists();

        if ($exists) {
            return;
        }

        Task::create([
            'project_id'                => $projectId,
            'creator_profile_id'        => $profile->id,
            'external_task_key'         => (string) Str::uuid(),
            'platform'                  => (string) $previousTask->platform,
            'handle'                    => (string) $previousTask->handle,
            'task_type'                 => $taskType,
            'priority'                  => (string) $previousTask->priority,
            'status'                    => 'PENDING',
            'due_at'                    => now()->addHours(24),
            'open_url'                  => (string) ($previousTask->open_url ?? ''),
            'platform_connection_state' => (string) ($previousTask->platform_connection_state ?? 'none'),
            'follow_up_count'           => (int) ($previousTask->follow_up_count ?? 0),
            'source_provider'           => 'auto',
            'source_reference'          => 'task:' . ($previousTask->external_task_key ?: $previousTask->id),
            'notes'                     => 'Auto-generated after ' . $previousTask->task_type,
            'metadata'                  => [
                'parent_task_id' => (string) ($previousTask->external_task_key ?: $previousTask->id),
            ],
        ]);
    }

    // ─── Task type determination ──────────────────────────────────────────────

    private function determineInitialTaskType(array $creator): ?string
    {
        $status           = strtoupper(trim((string) ($creator['Status'] ?? '')));
        $accepted         = strtoupper(trim((string) ($creator['Accepted_(Y/N)'] ?? 'N')));
        $preferredChannel = strtoupper(trim((string) ($creator['Preferred_Channel'] ?? 'DM')));
        $hasEmail         = trim((string) ($creator['Contact_Email'] ?? '')) !== '';
        $dmSent           = trim((string) ($creator['DM_Sent_Date'] ?? '')) !== '';
        $responseDate     = trim((string) ($creator['Response_Date'] ?? '')) !== '';
        $followUpNeeded   = strtoupper(trim((string) ($creator['Follow_Up_Needed_(Y/N)'] ?? 'N')));
        $platform         = strtolower(trim((string) ($creator['Platform'] ?? '')));

        if ($accepted === 'Y' && !$dmSent) {
            return 'DM_INVITE';
        }

        if ($dmSent && !$responseDate && $followUpNeeded === 'Y') {
            return 'DM_FOLLOWUP';
        }

        if (in_array($status, ['', 'NEW', 'ENRICHED', 'DISCOVERED'], true)) {
            if ($preferredChannel === 'EMAIL' && $hasEmail) {
                return 'EMAIL_SEND';
            }
            if (in_array($platform, self::REQUIRES_CONNECTION, true)) {
                return 'FOLLOW_REQUEST';
            }
            return 'DM_INVITE';
        }

        return null;
    }

    private function determineInitialTaskTypeFromProfile(CreatorProfile $profile): ?string
    {
        $status           = strtoupper(trim((string) ($profile->status ?: '')));
        $accepted         = (bool) $profile->accepted_flag;
        $preferredChannel = strtoupper(trim((string) ($profile->preferred_channel ?: 'DM')));
        $hasEmail         = filled(optional($profile->creator)->primary_email);
        $dmSent           = $profile->dm_sent_at !== null;
        $responseDate     = $profile->responded_at !== null;
        $followUpNeeded   = (bool) $profile->follow_up_needed;
        $platform         = strtolower((string) ($profile->platform ?? ''));

        if ($accepted && !$dmSent) {
            return 'DM_INVITE';
        }

        if ($dmSent && !$responseDate && $followUpNeeded) {
            return 'DM_FOLLOWUP';
        }

        if (in_array($status, ['', 'NEW', 'ENRICHED', 'DISCOVERED'], true)) {
            if ($preferredChannel === 'EMAIL' && $hasEmail) {
                return 'EMAIL_SEND';
            }
            if (in_array($platform, self::REQUIRES_CONNECTION, true)) {
                return 'FOLLOW_REQUEST';
            }
            return 'DM_INVITE';
        }

        return null;
    }

    // ─── Profile state mutations ──────────────────────────────────────────────

    private function applyTaskToCreatorProfile(CreatorProfile $profile, Task $task): void
    {
        $taskType = (string) $task->task_type;
        $status   = strtoupper((string) ($task->status ?? ''));

        if ($profile->creator) {
            $notes     = trim((string) (optional($profile->creator)->notes ?? ''));
            $timestamp = now()->toDateTimeString();
            $profile->creator->notes = trim($notes . ' | Task completed: ' . $taskType . ' @ ' . $timestamp, ' |');
            $profile->creator->save();
        }

        if (!in_array($status, ['COMPLETED', 'DONE'], true)) {
            return;
        }

        switch ($taskType) {
            case 'FOLLOW_REQUEST':
                $profile->status = 'FOLLOW_REQUEST_SENT';
                break;
            case 'DM_INVITE':
            case 'EMAIL_SEND':
                $profile->status           = 'CONTACTED';
                $profile->dm_sent_at       = now();
                $profile->follow_up_needed = true;
                break;
            case 'DM_FOLLOWUP':
                $profile->status = 'FOLLOWED_UP';
                break;
            case 'COMMENT_ON_POST':
                $profile->comment_attempted_at = now();
                $profile->status               = 'COMMENT_ATTEMPTED';
                break;
            case 'NEGOTIATE_TERMS':
                $profile->status = 'NEGOTIATING';
                break;
            case 'CONFIRM_ACCEPTED':
                $profile->accepted_flag = true;
                $profile->status        = 'ACCEPTED';
                break;
            case 'CHECK_IN':
                $profile->status = 'ACTIVE';
                break;
            case 'CONFIRM_POSTED':
                $profile->status = 'POSTED';
                break;
            case 'ARCHIVE_CREATOR':
                $profile->status = 'ARCHIVED';
                break;
        }
    }

    private function applyTaskToCreator(array $creator, array $task): array
    {
        $taskType  = (string) ($task['Task_Type'] ?? '');
        $timestamp = now()->toDateTimeString();
        $creator['Notes'] = trim(((string) ($creator['Notes'] ?? '')) . ' | Task completed: ' . $taskType . ' @ ' . $timestamp, ' |');

        switch ($taskType) {
            case 'FOLLOW_REQUEST':
                $creator['Status'] = 'FOLLOW_REQUEST_SENT';
                break;
            case 'DM_INVITE':
                $creator['Status']                    = 'CONTACTED';
                $creator['DM_Sent_Date']              = now()->toDateString();
                $creator['Follow_Up_Needed_(Y/N)']    = 'Y';
                break;
            case 'EMAIL_SEND':
                $creator['Status']                    = 'CONTACTED';
                $creator['DM_Sent_Date']              = now()->toDateString();
                $creator['Follow_Up_Needed_(Y/N)']    = 'Y';
                break;
            case 'DM_FOLLOWUP':
                $creator['Status'] = 'FOLLOWED_UP';
                break;
            case 'NEGOTIATE_TERMS':
                $creator['Status'] = 'NEGOTIATING';
                break;
            case 'CONFIRM_ACCEPTED':
                $creator['Accepted_(Y/N)'] = 'Y';
                $creator['Status']         = 'ACCEPTED';
                break;
            case 'CHECK_IN':
                $creator['Status'] = 'ACTIVE';
                break;
            case 'CONFIRM_POSTED':
                $creator['Status'] = 'POSTED';
                break;
            case 'ARCHIVE_CREATOR':
                $creator['Status'] = 'ARCHIVED';
                break;
        }

        return $creator;
    }

    // ─── Template helpers ────────────────────────────────────────────────────

    private function pickTemplate(array $templates, string $platform, string $taskType): array
    {
        if ($templates === []) {
            return [];
        }

        $targetPlatform = $taskType === 'EMAIL_SEND' ? 'email' : strtolower(trim($platform));
        $targetStage    = $this->stageFromTaskType($taskType);

        $scored = [];
        foreach ($templates as $template) {
            $templatePlatform = strtolower(trim((string) ($template['Best_For_Platform'] ?? '')));
            $meta             = $this->parseTemplateMeta((string) ($template['Psychological_Trigger'] ?? ''));
            $templateStage    = strtolower(trim((string) ($meta['stage'] ?? 'cold_invite')));
            $score            = 0;

            if ($templatePlatform === $targetPlatform) {
                $score += 4;
            } elseif ($targetPlatform !== 'email' && $templatePlatform === strtolower(trim($platform))) {
                $score += 2;
            }

            if ($templateStage === $targetStage) {
                $score += 3;
            }

            $scored[] = ['score' => $score, 'template' => $template];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $scored[0]['template'] ?? $templates[0];
    }

    private function pickTemplateFromDatabase(array $templates, string $platform, string $taskType): ?MessageTemplate
    {
        if ($templates === []) {
            return null;
        }

        $targetPlatform = $taskType === 'EMAIL_SEND' ? 'email' : strtolower(trim($platform));
        $targetStage    = $this->stageFromTaskType($taskType);

        usort($templates, function (MessageTemplate $a, MessageTemplate $b) use ($targetPlatform, $targetStage, $platform) {
            $score = function (MessageTemplate $t) use ($targetPlatform, $targetStage, $platform) {
                $s         = 0;
                $tPlatform = strtolower(trim((string) ($t->platform ?: '')));
                $tStage    = strtolower(trim((string) ($t->stage ?: 'cold_invite')));

                if ($tPlatform === $targetPlatform) {
                    $s += 4;
                } elseif ($targetPlatform !== 'email' && $tPlatform === strtolower(trim($platform))) {
                    $s += 2;
                }

                if ($tStage === $targetStage) {
                    $s += 3;
                }

                return $s;
            };

            return $score($b) <=> $score($a);
        });

        return $templates[0] ?? null;
    }

    private function stageFromTaskType(string $taskType): string
    {
        return match ($taskType) {
            'DM_FOLLOWUP'      => 'follow_up',
            'CONFIRM_ACCEPTED' => 'after_accept',
            'NEGOTIATE_TERMS'  => 'negotiation',
            'CHECK_IN'         => 'check_in',
            'CONFIRM_POSTED'   => 'post_confirmation',
            default            => 'cold_invite',
        };
    }

    private function parseTemplateMeta(string $psychologicalTrigger): array
    {
        $text   = trim($psychologicalTrigger);
        $result = ['trigger' => $text, 'stage' => 'cold_invite', 'niche' => '', 'notes' => ''];

        if (!str_contains($text, '|| META:')) {
            return $result;
        }

        [$trigger, $metaJson] = explode('|| META:', $text, 2);
        $decoded = json_decode(trim($metaJson), true);

        if (!is_array($decoded)) {
            $result['trigger'] = trim($trigger);
            return $result;
        }

        return [
            'trigger' => trim($trigger),
            'stage'   => (string) ($decoded['stage'] ?? 'cold_invite'),
            'niche'   => (string) ($decoded['niche'] ?? ''),
            'notes'   => (string) ($decoded['notes'] ?? ''),
        ];
    }

    private function buildMessageDraft(array $template, array $creator, string $taskType): string
    {
        $base   = (string) ($template['DM_Template'] ?? $this->defaultMessageBase($taskType));
        $handle = ltrim((string) ($creator['Handle'] ?? ''), '@');
        $name   = (string) ($creator['Name'] ?? 'there');

        return str_replace(['{{handle}}', '{{name}}'], [$handle, $name], $base);
    }

    private function buildMessageDraftFromProfile(?MessageTemplate $template, CreatorProfile $profile, string $taskType): string
    {
        $base   = (string) ($template?->copy ?: $this->defaultMessageBase($taskType));
        $handle = ltrim((string) ($profile->handle ?: ''), '@');
        $name   = (string) (optional($profile->creator)->display_name ?: 'there');

        return str_replace(['{{handle}}', '{{name}}'], [$handle, $name], $base);
    }

    private function defaultMessageBase(string $taskType): string
    {
        return match ($taskType) {
            'EMAIL_SEND'      => 'Hey {{name}}, I think your content could be a strong fit for a partnership.',
            'DM_FOLLOWUP'     => 'Hey {{handle}}, just following up in case you missed my last message.',
            'COMMENT_ON_POST' => 'Love this content! 🙌',
            'NEGOTIATE_TERMS' => 'Hey {{handle}}, excited to work together — here are the details.',
            'CHECK_IN'        => 'Hey {{handle}}, just checking in on the campaign. How is everything going?',
            'CONFIRM_POSTED'  => 'Hey {{handle}}, could you share the link to your post so we can track it?',
            default           => 'Hey {{handle}}, loved your content.',
        };
    }

    // ─── Scoring / priority ──────────────────────────────────────────────────

    private function priorityFromCreator(array $creator): string
    {
        $rawScore = (string) ($creator['Value_Score'] ?? '');
        $score    = is_numeric($rawScore) ? (float) $rawScore : 0.0;

        if ($score <= 0) {
            $score = (float) $this->scoring->score($creator);
        }

        return match ($this->scoring->tier($score)) {
            'HIGH'   => 'HIGH',
            'MEDIUM' => 'MEDIUM',
            default  => 'LOW',
        };
    }

    private function priorityFromProfile(CreatorProfile $profile): string
    {
        $score = (float) ($profile->value_score ?? 0);

        return match ($this->scoring->tier($score)) {
            'HIGH'   => 'HIGH',
            'MEDIUM' => 'MEDIUM',
            default  => 'LOW',
        };
    }

    // ─── Normalization helpers ────────────────────────────────────────────────

    private function normalizeSheetTaskRow(array $row): array
    {
        return [
            'taskId'                  => (string) ($row['Task_ID'] ?? ''),
            'taskType'                => (string) ($row['Task_Type'] ?? ''),
            'platform'                => strtolower((string) ($row['Platform'] ?? 'instagram')),
            'handle'                  => (string) ($row['Handle'] ?? ''),
            'profileUrl'              => (string) ($row['Open_URL'] ?? ''),
            'dmUrl'                   => (string) ($row['Open_URL'] ?? ''),
            'status'                  => $this->normalizeStatus(strtoupper((string) ($row['Status'] ?? 'PENDING'))),
            'priority'                => $this->normalizePriority(strtoupper((string) ($row['Priority'] ?? 'LOW'))),
            'dueDate'                 => (string) ($row['Due_At'] ?? ''),
            'createdAt'               => (string) ($row['Created_At'] ?? ''),
            'completedAt'             => (string) ($row['Completed_At'] ?? ''),
            'snoozedUntil'            => null,
            'messageText'             => (string) ($row['Message_Draft'] ?? ''),
            'notes'                   => (string) ($row['Notes'] ?? ''),
            'followUpCount'           => 0,
            'platformConnectionState' => 'none',
            'profilePicUrl'           => null,
        ];
    }

    private function normalizeDbTask(Task $task): array
    {
        return [
            'taskId'                  => (string) ($task->external_task_key ?: $task->id),
            'taskType'                => (string) $task->task_type,
            'platform'                => strtolower((string) ($task->platform ?: 'instagram')),
            'handle'                  => (string) ($task->handle ?: ''),
            'profileUrl'              => (string) ($task->open_url ?: ''),
            'dmUrl'                   => (string) ($task->open_url ?: ''),
            'status'                  => $this->normalizeStatus(strtoupper((string) ($task->status ?: 'PENDING'))),
            'priority'                => $this->normalizePriority(strtoupper((string) ($task->priority ?: 'LOW'))),
            'dueDate'                 => optional($task->due_at)?->toDateTimeString() ?? '',
            'createdAt'               => optional($task->created_at)?->toDateTimeString() ?? '',
            'completedAt'             => optional($task->completed_at)?->toDateTimeString() ?? '',
            'snoozedUntil'            => optional($task->snoozed_until)?->toIso8601String(),
            'messageText'             => (string) ($task->message_draft ?: ''),
            'notes'                   => (string) ($task->notes ?: ''),
            'followUpCount'           => (int) ($task->follow_up_count ?? 0),
            'platformConnectionState' => (string) ($task->platform_connection_state ?: 'none'),
            'profilePicUrl'           => (string) ($task->creatorProfile?->profile_pic_url ?: ''),
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'DONE', 'COMPLETED' => 'completed',
            'SKIPPED'           => 'skipped',
            'IN_PROGRESS'       => 'in_progress',
            'SNOOZED'           => 'snoozed',
            'ARCHIVED'          => 'archived',
            default             => 'pending',
        };
    }

    private function normalizePriority(string $priority): string
    {
        return match ($priority) {
            'URGENT' => 'urgent',
            'HIGH'   => 'high',
            'MEDIUM' => 'medium',
            default  => 'low',
        };
    }

    // ─── Misc helpers ────────────────────────────────────────────────────────

    private function initialConnectionState(string $platform, CreatorProfile $profile): string
    {
        if (!in_array($platform, self::REQUIRES_CONNECTION, true)) {
            return 'none';
        }

        return match (strtoupper((string) ($profile->status ?? ''))) {
            'FOLLOW_REQUEST_SENT'                                              => 'pending',
            'CONTACTED', 'FOLLOWED_UP', 'NEGOTIATING', 'ACCEPTED', 'ACTIVE', 'POSTED' => 'connected',
            default                                                            => 'none',
        };
    }

    private function taskUniqKey(string $platform, string $handle, string $taskType): string
    {
        return strtolower(trim($platform)) . '|' . strtolower(trim($handle)) . '|' . strtoupper(trim($taskType));
    }

    private function channelFromTaskType(string $taskType, array $creator): string
    {
        return match ($taskType) {
            'EMAIL_SEND' => 'Email',
            default      => (string) ($creator['Platform'] ?? ''),
        };
    }

    private function eventTypeFromTask(string $taskType, string $status = 'COMPLETED'): string
    {
        $status = strtoupper(trim($status));

        if ($status === 'SNOOZED') return 'TASK_SNOOZED';
        if ($status === 'SKIPPED') return 'TASK_SKIPPED';

        return match ($taskType) {
            'FOLLOW_REQUEST'   => 'FOLLOW_SENT_CONFIRMED',
            'DM_INVITE'        => 'DM_SENT_CONFIRMED',
            'DM_FOLLOWUP'      => 'FOLLOWUP_SENT_CONFIRMED',
            'EMAIL_SEND'       => 'EMAIL_SENT',
            'COMMENT_ON_POST'  => 'COMMENT_POSTED',
            'NEGOTIATE_TERMS'  => 'TERMS_SENT',
            'CHECK_IN'         => 'CHECK_IN_SENT',
            'CONFIRM_POSTED'   => 'POST_CONFIRMED',
            'CONFIRM_ACCEPTED' => 'FOLLOW_ACCEPTED_CONFIRMED',
            'ARCHIVE_CREATOR'  => 'CREATOR_ARCHIVED',
            default            => 'TASK_COMPLETED',
        };
    }

    private function findCreator(string $sheetId, string $platform, string $handle): ?array
    {
        foreach ($this->sheets->getRows($sheetId, 'Creators_CRM') as $creator) {
            if (strcasecmp((string) ($creator['Platform'] ?? ''), $platform) === 0
                && strcasecmp((string) ($creator['Handle'] ?? ''), $handle) === 0) {
                return $creator;
            }
        }

        return null;
    }

    private function extractSheetRowNumber(string $sourceReference, string $sheetName): int
    {
        if (!str_starts_with($sourceReference, $sheetName . ':')) {
            return 0;
        }

        return max(0, (int) substr($sourceReference, strlen($sheetName) + 1));
    }
}
