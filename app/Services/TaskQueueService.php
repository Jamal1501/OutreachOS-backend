<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

class TaskQueueService
{
    public function __construct(
        private GoogleSheetsService $sheets,
        private OutreachLogService $outreachLog,
        private InfluencerScoringService $scoring,
    ) {
    }

    public function generateInitialTasks(string $sheetId, array $options = []): array
    {
        $limit = max(1, (int) ($options['limit'] ?? 50));
        $crmRows = $this->sheets->getRows($sheetId, 'Creators_CRM');
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

        foreach ($crmRows as $creator) {
            if ($created >= $limit) {
                break;
            }

            $taskType = $this->determineInitialTaskType($creator);
            if ($taskType === null) {
                continue;
            }

            $taskKey = $this->taskUniqKey($creator['Platform'] ?? '', $creator['Handle'] ?? '', $taskType);
            if (isset($existingTaskKeys[$taskKey])) {
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

        $this->sheets->appendAssocRows($sheetId, 'Task_Queue', $recordsToAppend, $taskHeaders);

        foreach ($logEvents as $event) {
            $this->outreachLog->appendEvent($sheetId, $event);
        }

        return [
            'created' => $created,
            'taskSheet' => 'Task_Queue',
        ];
    }

    public function completeTask(string $sheetId, string $taskId, array $payload = []): array
    {
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
        }

        $eventId = $this->outreachLog->appendEvent($sheetId, [
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
        ];
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
}
