<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

class TaskQueueService
{
    public function __construct(
        private GoogleSheetsService $sheets,
        private OutreachLogService $outreachLog,
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

            $template = $this->pickTemplate($messageLibrary, $creator['Platform'] ?? '');
            $taskId = (string) Str::uuid();
            $openUrl = (string) ($creator['DM_Link'] ?? '');
            $messageDraft = $this->buildMessageDraft($template, $creator);

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

        $task['Status'] = (string) ($payload['status'] ?? 'COMPLETED');
        $task['Completed_At'] = now()->toDateTimeString();
        $task['Notes'] = trim(((string) ($task['Notes'] ?? '')) . ' ' . ((string) ($payload['notes'] ?? '')));

        $this->sheets->updateAssocRow($sheetId, 'Task_Queue', (int) $task['_row_number'], $task);

        $creator = $this->findCreator($sheetId, (string) ($task['Platform'] ?? ''), (string) ($task['Handle'] ?? ''));
        if ($creator) {
            $creator = $this->applyTaskToCreator($creator, $task);
            $this->sheets->updateAssocRow($sheetId, 'Creators_CRM', (int) $creator['_row_number'], $creator);
        }

        $eventType = $this->eventTypeFromTask((string) ($task['Task_Type'] ?? ''));
        $eventId = $this->outreachLog->appendEvent($sheetId, [
            'Platform' => (string) ($task['Platform'] ?? ''),
            'Handle' => (string) ($task['Handle'] ?? ''),
            'Channel' => $this->channelFromTaskType((string) ($task['Task_Type'] ?? ''), $creator ?? []),
            'Event_Type' => $eventType,
            'Template_ID' => (string) ($task['Template_ID'] ?? ''),
            'Sender_Account' => (string) ($payload['sender_account'] ?? ''),
            'Status' => (string) ($task['Status'] ?? 'COMPLETED'),
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

    private function pickTemplate(array $templates, string $platform): array
    {
        foreach ($templates as $template) {
            if (strcasecmp((string) ($template['Best_For_Platform'] ?? ''), $platform) === 0) {
                return $template;
            }
        }

        return $templates[0] ?? [];
    }

    private function buildMessageDraft(array $template, array $creator): string
    {
        $base = (string) ($template['DM_Template'] ?? 'Hey {{handle}}, loved your content.');
        $handle = ltrim((string) ($creator['Handle'] ?? ''), '@');
        $name = (string) ($creator['Name'] ?? 'there');

        return str_replace(['{{handle}}', '{{name}}'], [$handle, $name], $base);
    }

    private function priorityFromCreator(array $creator): string
    {
        $score = (float) ($creator['Value_Score'] ?? 0);

        if ($score >= 70) {
            return 'HIGH';
        }

        if ($score >= 40) {
            return 'MEDIUM';
        }

        return 'NORMAL';
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

    private function eventTypeFromTask(string $taskType): string
    {
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
}
