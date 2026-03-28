<?php

namespace App\Services;

use App\Models\CreatorProfile;
use App\Models\MessageTemplate;
use App\Models\OutreachEvent;
use App\Models\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OutreachLogService
{
    public function __construct(
        private GoogleSheetsService $sheets,
        private OperationalMirrorService $mirror,
        private ProjectResolverService $projects,
    ) {
    }

    public function appendEvent(string $sheetId, array $payload): string
    {
        $eventIds = $this->appendEvents($sheetId, [$payload]);

        return $eventIds[0] ?? (string) Str::uuid();
    }

    public function appendEvents(string $sheetId, array $payloads): array
    {
        if ($payloads === []) {
            return [];
        }

        $eventIds = [];
        $records = [];

        foreach ($payloads as $payload) {
            $eventId = (string) ($payload['Event_ID'] ?? Str::uuid());
            $eventIds[] = $eventId;

            $platform = strtolower(trim((string) ($payload['Platform'] ?? '')));
            $handle = $this->normalizeHandle((string) ($payload['Handle'] ?? ''));
            $templateId = trim((string) ($payload['Template_ID'] ?? ''));
            $url = trim((string) ($payload['URL'] ?? ''));
            $sentAt = (string) ($payload['Sent_At'] ?? now()->toDateTimeString());

            $records[] = [
                'Event_ID' => $eventId,
                'Platform' => $platform,
                'Handle' => $handle,
                'Channel' => (string) ($payload['Channel'] ?? ''),
                'Event_Type' => (string) ($payload['Event_Type'] ?? ''),
                'Template_ID' => $templateId,
                'Sender_Account' => (string) ($payload['Sender_Account'] ?? ''),
                'Sent_At' => $sentAt,
                'Status' => (string) ($payload['Status'] ?? ''),
                'URL' => $url,
                'Notes' => (string) ($payload['Notes'] ?? ''),
            ];
        }

        $project = $this->projects->findByWorkbookId($sheetId);
        if ($project) {
            foreach ($payloads as $index => $payload) {
                $record = $records[$index];
                $task = $this->findTask($project->id, $payload);
                $profile = $this->findCreatorProfile($project->id, $record['Platform'], $record['Handle'], $payload, $task);
                $template = $this->findMessageTemplate($project->id, (string) $record['Template_ID']);

                OutreachEvent::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'external_event_key' => (string) $record['Event_ID'],
                    ],
                    [
                        'creator_profile_id' => $profile?->id,
                        'task_id' => $task?->id,
                        'message_template_id' => $template?->id,
                        'platform' => $record['Platform'] ?: null,
                        'handle' => $record['Handle'] ?: null,
                        'channel' => trim((string) $record['Channel']) ?: null,
                        'event_type' => trim((string) $record['Event_Type']) ?: null,
                        'sender_account' => trim((string) $record['Sender_Account']) ?: null,
                        'sent_at' => $this->parseDateTime($record['Sent_At']),
                        'status' => trim((string) $record['Status']) ?: null,
                        'url' => trim((string) $record['URL']) ?: null,
                        'notes' => trim((string) $record['Notes']) ?: null,
                        'metadata' => [
                            'sheet_sync_pending' => true,
                            'task_external_key' => $task?->external_task_key,
                        ],
                    ],
                );
            }
        }

        try {
            $headers = $this->sheets->getHeaders($sheetId, 'Outreach_Log');
            $this->sheets->appendAssocRows($sheetId, 'Outreach_Log', $records, $headers);

            if ($project && $this->mirror->enabled()) {
                $this->mirror->syncOutreachEvents($sheetId, $eventIds);
            }
        } catch (\Throwable $e) {
            Log::warning('Outreach_Log sheet sync failed after database write', [
                'sheet_id' => $sheetId,
                'event_ids' => $eventIds,
                'error' => $e->getMessage(),
            ]);
        }

        return $eventIds;
    }

    private function parseDateTime(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function normalizeHandle(string $handle): string
    {
        $handle = trim($handle);
        if ($handle === '') {
            return '';
        }

        return Str::startsWith($handle, '@') ? $handle : '@' . ltrim($handle, '@');
    }

    private function findTask(string $projectId, array $payload): ?Task
    {
        $taskExternalKey = trim((string) (Arr::get($payload, 'Task_ID') ?: Arr::get($payload, 'task_id') ?: Arr::get($payload, 'taskId') ?: ''));
        if ($taskExternalKey !== '') {
            return Task::query()
                ->where('project_id', $projectId)
                ->where(function ($query) use ($taskExternalKey) {
                    $query->where('external_task_key', $taskExternalKey)->orWhere('id', $taskExternalKey);
                })
                ->first();
        }

        return null;
    }

    private function findCreatorProfile(string $projectId, string $platform, string $handle, array $payload, ?Task $task): ?CreatorProfile
    {
        if ($task?->creator_profile_id) {
            return CreatorProfile::query()->where('id', $task->creator_profile_id)->first();
        }

        $profileId = trim((string) (Arr::get($payload, 'creator_profile_id') ?: Arr::get($payload, 'creatorProfileId') ?: ''));
        if ($profileId !== '') {
            return CreatorProfile::query()->where('project_id', $projectId)->where('id', $profileId)->first();
        }

        if ($platform === '' && $handle === '') {
            return null;
        }

        return CreatorProfile::query()
            ->where('project_id', $projectId)
            ->where('platform', $platform)
            ->where('handle', $handle)
            ->first();
    }

    private function findMessageTemplate(string $projectId, string $templateId): ?MessageTemplate
    {
        $templateId = trim($templateId);
        if ($templateId === '') {
            return null;
        }

        $query = MessageTemplate::query()
            ->where('project_id', $projectId)
            ->where('angle_id', $templateId);

        if ($this->isUuid($templateId)) {
            $query->orWhere(function ($nested) use ($projectId, $templateId) {
                $nested->where('project_id', $projectId)->where('id', $templateId);
            });
        }

        return $query->first();
    }
    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            trim($value)
        );
    }

}
