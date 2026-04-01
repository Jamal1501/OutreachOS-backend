<?php

namespace App\Services;

use App\Models\CreatorProfile;
use App\Models\MessageTemplate;
use App\Models\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class TaskQueueService
{
    public function __construct(
        private GoogleSheetsService $sheets,
        private OutreachLogService $outreachLog,
        private InfluencerScoringService $scoring,
        private OperationalMirrorService $mirror,
        private ProjectResolverService $projects,
    ) {
    }

    public function generateInitialTasks(string $sheetId, array $options = []): array
    {
        if ($this->mirror->enabled()) {
            $dbResult = $this->generateInitialTasksFromDatabase($sheetId, $options);
            if ($dbResult !== null) {
                return $dbResult;
            }
        }

        $limit = max(1, (int) ($options['limit'] ?? 50));
        $crmRows = $this->sheets->getRows($sheetId, 'Creators_CRM');
        $targetRowNumbers = array_key_exists('rowNumbers', $options)
            ? array_values(array_unique(array_filter(array_map('intval', (array) ($options['rowNumbers'] ?? [])), fn (int $rowNumber) => $rowNumber > 1)))
            : null;
        if (is_array($targetRowNumbers)) {
            $rowLookup = array_fill_keys($targetRowNumbers, true);
            $crmRows = array_values(array_filter(
                $crmRows,
                fn (array $creator) => isset($rowLookup[(int) ($creator['_row_number'] ?? 0)])
            ));
            usort($crmRows, fn (array $a, array $b) => ((int) ($a['_row_number'] ?? 0)) <=> ((int) ($b['_row_number'] ?? 0)));
        }
        $taskHeaders = $this->sheets->getHeaders($sheetId, 'Task_Queue');
        $openTasks = $this->sheets->getRows($sheetId, 'Task_Queue');
        $messageLibrary = $this->sheets->getRows($sheetId, 'Message_Library');

        $existingTaskKeys = [];
        foreach ($openTasks as $task) {
            $status = strtoupper((string) ($task['Status'] ?? ''));
            if (!in_array($status, ['DONE', 'COMPLETED', 'SKIPPED'], true)) {
                $existingTaskKeys[$this->taskUniqKey($task['Platform'] ?? '', $task['Handle'] ?? '', $task['Task_Type'] ?? '')] = true;
            }
        }

        $recordsToAppend = [];
        $logEvents = [];
        $created = 0;
        $eligible = 0;
        $skippedExisting = 0;
        $skippedIneligible = 0;

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

            $template = $this->pickTemplate($messageLibrary, (string) ($creator['Platform'] ?? ''), $taskType);
            $taskId = (string) Str::uuid();
            $openUrl = (string) ($creator['DM_Link'] ?? '');
            $messageDraft = $this->buildMessageDraft($template, $creator, $taskType);

            $record = [
                'Task_ID' => $taskId,
                'Platform' => (string) ($creator['Platform'] ?? ''),
                'Handle' => (string) ($creator['Handle'] ?? ''),
                'Task_Type' => $taskType,
                'Priority' => $this->priorityFromCreator($creator),
                'Status' => 'PENDING',
                'Due_At' => now()->toDateTimeString(),
                'Open_URL' => $openUrl,
                'Message_Draft' => $messageDraft,
                'Template_ID' => (string) ($template['Angle_Name'] ?? ''),
                'Created_At' => now()->toDateTimeString(),
                'Completed_At' => '',
                'Notes' => 'Auto-generated from Creators_CRM',
            ];

            $recordsToAppend[] = $record;
            $logEvents[] = [
                'Task_ID' => $taskId,
                'Platform' => $record['Platform'],
                'Handle' => $record['Handle'],
                'Channel' => $this->channelFromTaskType($taskType, $creator),
                'Event_Type' => 'TASK_CREATED',
                'Template_ID' => $record['Template_ID'],
                'Status' => 'PENDING',
                'URL' => $openUrl,
                'Notes' => $taskType,
            ];
            $existingTaskKeys[$taskKey] = true;
            $created++;
        }

        if ($recordsToAppend !== []) {
            $this->sheets->appendAssocRows($sheetId, 'Task_Queue', $recordsToAppend, $taskHeaders);
            $this->mirror->syncTasks($sheetId, array_map(fn (array $record) => (string) $record['Task_ID'], $recordsToAppend));
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
            'sourceRowNumbers' => $targetRowNumbers,
            'source' => 'google_sheets',
        ];
    }

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

        $status = strtoupper(trim((string) ($payload['status'] ?? 'COMPLETED')));
        $existingNotes = trim((string) ($task['Notes'] ?? ''));
        $newNotes = trim((string) ($payload['notes'] ?? ''));

        $task['Status'] = $status;
        $task['Completed_At'] = in_array($status, ['COMPLETED', 'DONE', 'SKIPPED'], true)
            ? now()->toDateTimeString()
            : '';
        $task['Notes'] = trim($existingNotes . ($newNotes !== '' ? ' ' . $newNotes : ''));

        $this->sheets->updateAssocRow($sheetId, 'Task_Queue', (int) $task['_row_number'], $task);

        $creator = $this->findCreator($sheetId, (string) ($task['Platform'] ?? ''), (string) ($task['Handle'] ?? ''));
        if ($creator && in_array($status, ['COMPLETED', 'DONE'], true)) {
            $creator = $this->applyTaskToCreator($creator, $task);
            $this->sheets->updateAssocRow($sheetId, 'Creators_CRM', (int) $creator['_row_number'], $creator);
            $this->mirror->syncCreators($sheetId, [(int) ($creator['_row_number'] ?? 0)]);
        }

        $this->mirror->syncTasks($sheetId, [$taskId]);

        $eventId = $this->outreachLog->appendEvent($sheetId, [
            'Task_ID' => $taskId,
            'Platform' => (string) ($task['Platform'] ?? ''),
            'Handle' => (string) ($task['Handle'] ?? ''),
            'Channel' => $this->channelFromTaskType((string) ($task['Task_Type'] ?? ''), $creator ?? []),
            'Event_Type' => $this->eventTypeFromTask((string) ($task['Task_Type'] ?? ''), $status),
            'Template_ID' => (string) ($task['Template_ID'] ?? ''),
            'Sender_Account' => (string) ($payload['sender_account'] ?? ''),
            'Status' => $status,
            'URL' => (string) ($task['Open_URL'] ?? ''),
            'Notes' => (string) ($payload['notes'] ?? ''),
        ]);

        return [
            'taskId' => $taskId,
            'eventId' => $eventId,
            'status' => $task['Status'],
            'source' => 'google_sheets',
        ];
    }

    private function completeTaskInDatabase(string $sheetId, string $taskId, array $payload = []): ?array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            return null;
        }

        $task = Task::query()
            ->where('project_id', $project->id)
            ->where(function ($query) use ($taskId) {
                $query->where('external_task_key', $taskId)->orWhere('id', $taskId);
            })
            ->with(['creatorProfile.creator', 'messageTemplate'])
            ->first();

        if (!$task) {
            return null;
        }

        if (array_key_exists('template_id', $payload) && $payload['template_id'] !== null) {
            $templateId = trim((string) $payload['template_id']);
            if ($templateId !== '') {
                $templateQuery = MessageTemplate::query()
                    ->where('project_id', $project->id)
                    ->where('angle_id', $templateId);

                if ($this->isUuid($templateId)) {
                    $templateQuery->orWhere(function ($query) use ($project, $templateId) {
                        $query->where('project_id', $project->id)->where('id', $templateId);
                    });
                }

                $template = $templateQuery->first();
                $task->message_template_id = $template?->id;
            }
        }

        if (array_key_exists('message_draft', $payload) && $payload['message_draft'] !== null) {
            $task->message_draft = (string) $payload['message_draft'];
        }

        $status = strtoupper(trim((string) ($payload['status'] ?? 'COMPLETED')));
        $existingNotes = trim((string) ($task->notes ?? ''));
        $newNotes = trim((string) ($payload['notes'] ?? ''));

        $task->status = $status;
        $task->completed_at = in_array($status, ['COMPLETED', 'DONE', 'SKIPPED'], true) ? now() : null;
        $task->notes = trim($existingNotes . ($newNotes !== '' ? ' ' . $newNotes : ''));
        $task->save();

        $profile = $task->creatorProfile;
        if ($profile && in_array($status, ['COMPLETED', 'DONE'], true)) {
            $this->applyTaskToCreatorProfile($profile, $task);
            $profile->save();
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
                    $taskRow['Status'] = $status;
                    $taskRow['Completed_At'] = $task->completed_at?->toDateTimeString() ?? '';
                    $taskRow['Notes'] = (string) ($task->notes ?? '');
                    $this->sheets->updateAssocRow($sheetId, 'Task_Queue', (int) $taskRow['_row_number'], $taskRow);
                    $sheetSync['task'] = true;
                }
            } catch (\Throwable $e) {
                Log::warning('Task_Queue sheet sync failed after database task completion', [
                    'sheet_id' => $sheetId,
                    'task_id' => $taskId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($profile && in_array($status, ['COMPLETED', 'DONE'], true)) {
            $creatorRowNumber = $this->extractSheetRowNumber((string) ($profile->source_reference ?? ''), 'Creators_CRM');
            if ($creatorRowNumber > 0) {
                try {
                    $creator = $this->findCreator($sheetId, (string) ($profile->platform ?? ''), (string) ($profile->handle ?? ''));
                    if ($creator) {
                        $creator = $this->applyTaskToCreator($creator, [
                            'Task_Type' => (string) $task->task_type,
                        ]);
                        $this->sheets->updateAssocRow($sheetId, 'Creators_CRM', (int) $creator['_row_number'], $creator);
                        $sheetSync['creator'] = true;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Creators_CRM sheet sync failed after database task completion', [
                        'sheet_id' => $sheetId,
                        'task_id' => $taskId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $eventId = $this->outreachLog->appendEvent($sheetId, [
            'Task_ID' => (string) ($task->external_task_key ?: $task->id),
            'creator_profile_id' => $profile?->id,
            'Platform' => (string) ($task->platform ?? ''),
            'Handle' => (string) ($task->handle ?? ''),
            'Channel' => $this->channelFromTaskType((string) $task->task_type, [
                'Platform' => (string) ($task->platform ?? ''),
                'Preferred_Channel' => (string) ($profile?->preferred_channel ?? ''),
            ]),
            'Event_Type' => $this->eventTypeFromTask((string) $task->task_type, $status),
            'Template_ID' => (string) ($task->messageTemplate?->angle_id ?: ''),
            'Sender_Account' => (string) ($payload['sender_account'] ?? ''),
            'Status' => $status,
            'URL' => (string) ($task->open_url ?? ''),
            'Notes' => (string) ($payload['notes'] ?? ''),
        ]);

        return [
            'taskId' => (string) ($task->external_task_key ?: $task->id),
            'eventId' => $eventId,
            'status' => $status,
            'source' => 'database',
            'sheetSync' => $sheetSync,
        ];
    }

    private function extractSheetRowNumber(string $sourceReference, string $sheetName): int
    {
        if (!Str::startsWith($sourceReference, $sheetName . ':')) {
            return 0;
        }

        return max(0, (int) substr($sourceReference, strlen($sheetName) + 1));
    }

    private function applyTaskToCreatorProfile(CreatorProfile $profile, Task $task): void
    {
        $taskType = (string) $task->task_type;
        $status = strtoupper((string) ($task->status ?? ''));

        $notes = trim((string) (optional($profile->creator)->notes ?? ''));
        $timestamp = now()->toDateTimeString();

        if ($profile->creator) {
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
                $profile->status = 'CONTACTED';
                $profile->dm_sent_at = now();
                $profile->follow_up_needed = true;
                break;
            case 'DM_FOLLOWUP':
                $profile->status = 'FOLLOWED_UP';
                break;
            case 'CONFIRM_ACCEPTED':
                $profile->accepted_flag = true;
                $profile->status = 'ACCEPTED';
                break;
        }
    }

    private function determineInitialTaskType(array $creator): ?string
    {
        $status = strtoupper(trim((string) ($creator['Status'] ?? '')));
        $accepted = strtoupper(trim((string) ($creator['Accepted_(Y/N)'] ?? 'N')));
        $preferredChannel = strtoupper(trim((string) ($creator['Preferred_Channel'] ?? 'DM')));
        $hasEmail = trim((string) ($creator['Contact_Email'] ?? '')) !== '';
        $dmSent = trim((string) ($creator['DM_Sent_Date'] ?? '')) !== '';
        $responseDate = trim((string) ($creator['Response_Date'] ?? '')) !== '';
        $followUpNeeded = strtoupper(trim((string) ($creator['Follow_Up_Needed_(Y/N)'] ?? 'N')));

        if ($accepted === 'Y' && !$dmSent) {
            return 'DM_INVITE';
        }

        if ($dmSent && !$responseDate && $followUpNeeded === 'Y') {
            return 'DM_FOLLOWUP';
        }

        if ($status === 'NEW' || $status === '' || $status === 'ENRICHED') {
            if ($preferredChannel === 'EMAIL' && $hasEmail) {
                return 'EMAIL_SEND';
            }

            return 'FOLLOW_REQUEST';
        }

        return null;
    }

    private function pickTemplate(array $templates, string $platform, string $taskType): array
    {
        if ($templates === []) {
            return [];
        }

        $targetPlatform = $taskType === 'EMAIL_SEND' ? 'email' : strtolower(trim($platform));
        $targetStage = match ($taskType) {
            'DM_FOLLOWUP' => 'follow_up',
            'CONFIRM_ACCEPTED' => 'after_accept',
            default => 'cold_invite',
        };

        $scored = [];
        foreach ($templates as $template) {
            $templatePlatform = strtolower(trim((string) ($template['Best_For_Platform'] ?? '')));
            $meta = $this->parseTemplateMeta((string) ($template['Psychological_Trigger'] ?? ''));
            $templateStage = strtolower(trim((string) ($meta['stage'] ?? 'cold_invite')));
            $score = 0;

            if ($templatePlatform === $targetPlatform) {
                $score += 4;
            } elseif ($targetPlatform !== 'email' && $templatePlatform === strtolower(trim($platform))) {
                $score += 2;
            }

            if ($templateStage === $targetStage) {
                $score += 3;
            }

            if ($taskType === 'FOLLOW_REQUEST' && $templateStage === 'cold_invite') {
                $score += 1;
            }

            $scored[] = [
                'score' => $score,
                'template' => $template,
            ];
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $scored[0]['template'] ?? $templates[0];
    }

    private function parseTemplateMeta(string $psychologicalTrigger): array
    {
        $text = trim($psychologicalTrigger);
        $result = [
            'trigger' => $text,
            'stage' => 'cold_invite',
            'niche' => '',
            'notes' => '',
        ];

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
            'stage' => (string) ($decoded['stage'] ?? 'cold_invite'),
            'niche' => (string) ($decoded['niche'] ?? ''),
            'notes' => (string) ($decoded['notes'] ?? ''),
        ];
    }

    private function buildMessageDraft(array $template, array $creator, string $taskType): string
    {
        $defaultBase = match ($taskType) {
            'EMAIL_SEND' => 'Hey {{name}}, I think your content could be a strong fit for a partnership.',
            'DM_FOLLOWUP' => 'Hey {{handle}}, just following up in case you missed my last message.',
            default => 'Hey {{handle}}, loved your content.',
        };

        $base = (string) ($template['DM_Template'] ?? $defaultBase);
        $handle = ltrim((string) ($creator['Handle'] ?? ''), '@');
        $name = (string) ($creator['Name'] ?? 'there');

        return str_replace(['{{handle}}', '{{name}}'], [$handle, $name], $base);
    }

    private function priorityFromCreator(array $creator): string
    {
        $rawScore = (string) ($creator['Value_Score'] ?? '');
        $score = is_numeric($rawScore) ? (float) $rawScore : 0.0;

        if ($score <= 0) {
            $score = $this->scoring->score($creator);
        }

        return match ($this->scoring->tier($score)) {
            'HIGH' => 'HIGH',
            'MEDIUM' => 'MEDIUM',
            default => 'LOW',
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
            default => (string) ($creator['Platform'] ?? ''),
        };
    }

    private function eventTypeFromTask(string $taskType, string $status = 'COMPLETED'): string
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
            'DM_INVITE' => 'DM_SENT_CONFIRMED',
            'DM_FOLLOWUP' => 'FOLLOWUP_SENT_CONFIRMED',
            'EMAIL_SEND' => 'EMAIL_SENT',
            'CONFIRM_ACCEPTED' => 'FOLLOW_ACCEPTED_CONFIRMED',
            default => 'TASK_COMPLETED',
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

    private function applyTaskToCreator(array $creator, array $task): array
    {
        $taskType = (string) ($task['Task_Type'] ?? '');
        $timestamp = now()->toDateTimeString();
        $creator['Notes'] = trim(((string) ($creator['Notes'] ?? '')) . ' | Task completed: ' . $taskType . ' @ ' . $timestamp, ' |');

        switch ($taskType) {
            case 'FOLLOW_REQUEST':
                $creator['Status'] = 'FOLLOW_REQUEST_SENT';
                break;
            case 'DM_INVITE':
                $creator['Status'] = 'CONTACTED';
                $creator['DM_Sent_Date'] = now()->toDateString();
                $creator['Follow_Up_Needed_(Y/N)'] = 'Y';
                break;
            case 'EMAIL_SEND':
                $creator['Status'] = 'CONTACTED';
                $creator['DM_Sent_Date'] = now()->toDateString();
                $creator['Follow_Up_Needed_(Y/N)'] = 'Y';
                break;
            case 'DM_FOLLOWUP':
                $creator['Status'] = 'FOLLOWED_UP';
                break;
            case 'CONFIRM_ACCEPTED':
                $creator['Accepted_(Y/N)'] = 'Y';
                $creator['Status'] = 'ACCEPTED';
                break;
        }

        return $creator;
    }

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

        usort($rows, function (array $a, array $b) {
            return strcmp((string) ($b['Created_At'] ?? ''), (string) ($a['Created_At'] ?? ''));
        });

        return array_map(function (array $row) {
            $status = strtoupper(trim((string) ($row['Status'] ?? 'PENDING')));
            $priority = strtoupper(trim((string) ($row['Priority'] ?? 'NORMAL')));

            return [
                'taskId' => (string) ($row['Task_ID'] ?? ''),
                'taskType' => (string) ($row['Task_Type'] ?? ''),
                'platform' => strtolower((string) ($row['Platform'] ?? 'instagram')),
                'handle' => (string) ($row['Handle'] ?? ''),
                'profileUrl' => (string) ($row['Open_URL'] ?? ''),
                'dmUrl' => (string) ($row['Open_URL'] ?? ''),
                'status' => match ($status) {
                    'DONE', 'COMPLETED' => 'completed',
                    'SKIPPED' => 'skipped',
                    'IN_PROGRESS' => 'in_progress',
                    'SNOOZED' => 'snoozed',
                    default => 'pending',
                },
                'priority' => match ($priority) {
                    'URGENT' => 'urgent',
                    'HIGH' => 'high',
                    'MEDIUM' => 'medium',
                    default => 'low',
                },
                'dueDate' => (string) ($row['Due_At'] ?? ''),
                'createdAt' => (string) ($row['Created_At'] ?? ''),
                'completedAt' => (string) ($row['Completed_At'] ?? ''),
                'messageText' => (string) ($row['Message_Draft'] ?? ''),
                'notes' => (string) ($row['Notes'] ?? ''),
            ];
        }, $rows);
    }

private function generateInitialTasksFromDatabase(string $sheetId, array $options = []): ?array
{
    $project = $this->projects->findByWorkbookId($sheetId);
    if (!$project) {
        return null;
    }

    $limit = max(1, (int) ($options['limit'] ?? 50));
    $targetRowNumbers = array_key_exists('rowNumbers', $options)
        ? array_values(array_unique(array_filter(array_map('intval', (array) ($options['rowNumbers'] ?? [])), fn (int $rowNumber) => $rowNumber > 1)))
        : null;
    $targetProfileIds = array_key_exists('profileIds', $options)
        ? array_values(array_unique(array_filter(array_map('strval', (array) ($options['profileIds'] ?? [])), fn (string $id) => trim($id) !== '')))
        : null;

    $profilesQuery = CreatorProfile::query()
        ->with('creator')
        ->where('project_id', $project->id)
        ->orderBy('created_at');

    if (is_array($targetProfileIds) && $targetProfileIds !== []) {
        $profilesQuery->whereIn('id', $targetProfileIds);
    } elseif (is_array($targetRowNumbers)) {
        $profilesQuery->where(function ($query) use ($targetRowNumbers) {
            foreach ($targetRowNumbers as $rowNumber) {
                $query->orWhere('source_reference', 'Creators_CRM:' . $rowNumber)
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
        ->whereNotIn('status', ['DONE', 'COMPLETED', 'SKIPPED'])
        ->get(['platform', 'handle', 'task_type'])
        ->map(fn (Task $task) => $this->taskUniqKey((string) $task->platform, (string) $task->handle, (string) $task->task_type))
        ->flip()
        ->all();

    $templates = MessageTemplate::query()
        ->where('project_id', $project->id)
        ->get()
        ->all();

    $logEvents = [];
    $created = 0;
    $eligible = 0;
    $skippedExisting = 0;
    $skippedIneligible = 0;

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

        $template = $this->pickTemplateFromDatabase($templates, (string) $profile->platform, $taskType);
        $taskId = (string) Str::uuid();
        $messageDraft = $this->buildMessageDraftFromProfile($template, $profile, $taskType);
        $priority = $this->priorityFromProfile($profile);
        $openUrl = (string) ($profile->dm_link ?: $profile->profile_url ?: '');

        Task::create([
            'project_id' => $project->id,
            'creator_profile_id' => $profile->id,
            'message_template_id' => $template?->id,
            'external_task_key' => $taskId,
            'platform' => strtolower((string) $profile->platform),
            'handle' => (string) $profile->handle,
            'task_type' => $taskType,
            'priority' => $priority,
            'status' => 'PENDING',
            'due_at' => now(),
            'open_url' => $openUrl,
            'message_draft' => $messageDraft,
            'source_provider' => 'database',
            'source_reference' => 'creator_profile:' . $profile->id,
            'notes' => 'Auto-generated from creator_profiles',
            'metadata' => [
                'creator_profile_id' => $profile->id,
                'source_sheet_row_number' => $profile->source_metadata['sheet_row_number'] ?? null,
            ],
        ]);

        $logEvents[] = [
            'Task_ID' => $taskId,
            'creator_profile_id' => $profile->id,
            'Platform' => strtolower((string) $profile->platform),
            'Handle' => (string) $profile->handle,
            'Channel' => $this->channelFromTaskType($taskType, [
                'Platform' => strtolower((string) $profile->platform),
                'Preferred_Channel' => (string) ($profile->preferred_channel ?: ''),
            ]),
            'Event_Type' => 'TASK_CREATED',
            'Template_ID' => (string) ($template?->angle_id ?: ''),
            'Status' => 'PENDING',
            'URL' => $openUrl,
            'Notes' => $taskType,
        ];

        $openTaskKeys[$taskKey] = true;
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
        'sourceRowNumbers' => $targetRowNumbers,
        'sourceProfileIds' => $targetProfileIds,
        'source' => 'database',
    ];
}

    private function listTasksFromDatabase(string $sheetId): ?array
    {
        $project = $this->projects->findByWorkbookId($sheetId);
        if (!$project) {
            return null;
        }

        $tasks = Task::query()->where('project_id', $project->id)->orderByDesc('created_at')->get();

        return $tasks->map(fn (Task $task) => [
            'taskId' => (string) ($task->external_task_key ?: $task->id),
            'taskType' => (string) $task->task_type,
            'platform' => strtolower((string) ($task->platform ?: 'instagram')),
            'handle' => (string) ($task->handle ?: ''),
            'profileUrl' => (string) ($task->open_url ?: ''),
            'dmUrl' => (string) ($task->open_url ?: ''),
            'status' => match (strtoupper((string) ($task->status ?: 'PENDING'))) {
                'DONE', 'COMPLETED' => 'completed',
                'SKIPPED' => 'skipped',
                'IN_PROGRESS' => 'in_progress',
                'SNOOZED' => 'snoozed',
                default => 'pending',
            },
            'priority' => match (strtoupper((string) ($task->priority ?: 'LOW'))) {
                'URGENT' => 'urgent',
                'HIGH' => 'high',
                'MEDIUM' => 'medium',
                default => 'low',
            },
            'dueDate' => optional($task->due_at)?->toDateTimeString() ?? '',
            'createdAt' => optional($task->created_at)?->toDateTimeString() ?? '',
            'completedAt' => optional($task->completed_at)?->toDateTimeString() ?? '',
            'messageText' => (string) ($task->message_draft ?: ''),
            'notes' => (string) ($task->notes ?: ''),
        ])->values()->all();
    }

    private function determineInitialTaskTypeFromProfile(CreatorProfile $profile): ?string
    {
        $status = strtoupper(trim((string) ($profile->status ?: '')));
        $accepted = (bool) $profile->accepted_flag;
        $preferredChannel = strtoupper(trim((string) ($profile->preferred_channel ?: 'DM')));
        $hasEmail = filled(optional($profile->creator)->primary_email);
        $dmSent = $profile->dm_sent_at !== null;
        $responseDate = $profile->responded_at !== null;
        $followUpNeeded = (bool) $profile->follow_up_needed;

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
            return 'FOLLOW_REQUEST';
        }

        return null;
    }

    private function pickTemplateFromDatabase(array $templates, string $platform, string $taskType): ?MessageTemplate
    {
        if ($templates === []) {
            return null;
        }

        $targetPlatform = $taskType === 'EMAIL_SEND' ? 'email' : strtolower(trim($platform));
        $targetStage = match ($taskType) {
            'DM_FOLLOWUP' => 'follow_up',
            'CONFIRM_ACCEPTED' => 'after_accept',
            default => 'cold_invite',
        };

        usort($templates, function (MessageTemplate $a, MessageTemplate $b) use ($targetPlatform, $targetStage, $platform, $taskType) {
            $scoreTemplate = function (MessageTemplate $template) use ($targetPlatform, $targetStage, $platform, $taskType) {
                $score = 0;
                $templatePlatform = strtolower(trim((string) ($template->platform ?: '')));
                $templateStage = strtolower(trim((string) ($template->stage ?: 'cold_invite')));
                if ($templatePlatform === $targetPlatform) {
                    $score += 4;
                } elseif ($targetPlatform !== 'email' && $templatePlatform === strtolower(trim($platform))) {
                    $score += 2;
                }
                if ($templateStage === $targetStage) {
                    $score += 3;
                }
                if ($taskType === 'FOLLOW_REQUEST' && $templateStage === 'cold_invite') {
                    $score += 1;
                }
                return $score;
            };

            return $scoreTemplate($b) <=> $scoreTemplate($a);
        });

        return $templates[0] ?? null;
    }

    private function buildMessageDraftFromProfile(?MessageTemplate $template, CreatorProfile $profile, string $taskType): string
    {
        $defaultBase = match ($taskType) {
            'EMAIL_SEND' => 'Hey {{name}}, I think your content could be a strong fit for a partnership.',
            'DM_FOLLOWUP' => 'Hey {{handle}}, just following up in case you missed my last message.',
            default => 'Hey {{handle}}, loved your content.',
        };

        $base = (string) ($template?->copy ?: $defaultBase);
        $handle = ltrim((string) ($profile->handle ?: ''), '@');
        $name = (string) (optional($profile->creator)->display_name ?: 'there');

        return str_replace(['{{handle}}', '{{name}}'], [$handle, $name], $base);
    }

    private function priorityFromProfile(CreatorProfile $profile): string
    {
        $score = (float) ($profile->value_score ?? 0);
        return match ($this->scoring->tier($score)) {
            'HIGH' => 'HIGH',
            'MEDIUM' => 'MEDIUM',
            default => 'LOW',
        };
    }
    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            trim($value)
        );
    }

}
