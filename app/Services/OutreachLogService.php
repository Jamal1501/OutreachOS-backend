<?php

namespace App\Services;

use App\Models\CreatorProfile;
use App\Models\MessageTemplate;
use App\Models\OutreachEvent;
use App\Models\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class OutreachLogService
{
    public function __construct(
        private ProjectResolverService $projects,
        private LearningEventService $learningEvents,
        private CreatorRelationshipTimelineService $relationshipTimeline,
    ) {}

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
            $messageText = trim((string) (
                Arr::get($payload, 'Message_Text')
                ?: Arr::get($payload, 'messageText')
                ?: Arr::get($payload, 'message_draft')
                ?: ''
            ));

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
                'Message_Text' => $messageText,
            ];
        }

        $project = $this->projects->findByWorkbookId($sheetId);
        if ($project) {
            foreach ($payloads as $index => $payload) {
                $record = $records[$index];
                $task = $this->findTask($project->id, $payload);
                $profile = $this->findCreatorProfile($project->id, $record['Platform'], $record['Handle'], $payload, $task);
                $template = $this->findMessageTemplate($project->id, (string) $record['Template_ID']);
                $useTaskDraftAsMessage = filter_var($payload['Use_Task_Draft_As_Message'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $messageText = $record['Message_Text'] !== ''
                    ? $record['Message_Text']
                    : ($useTaskDraftAsMessage ? trim((string) ($task?->message_draft ?: '')) : '');

                $event = OutreachEvent::updateOrCreate(
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
                        'metadata' => array_filter([
                            'sheet_sync_pending' => true,
                            'task_external_key' => $task?->external_task_key,
                            'message_text' => $messageText !== '' ? $messageText : null,
                            'message_direction' => $this->eventDirection((string) $record['Event_Type']),
                        ], fn ($value) => $value !== null && $value !== ''),
                    ],
                );

                if ($profile) {
                    $this->applyOutreachEventToCreatorProfile($profile, $event);
                }

                $this->learningEvents->recordOutreachEvent($event->fresh(['creatorProfile.creator', 'task', 'messageTemplate']) ?: $event, $project);
                $this->relationshipTimeline->recordOutreachEvent($event->fresh(['task', 'messageTemplate']) ?: $event, $project);
            }
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

        return Str::startsWith($handle, '@') ? $handle : '@'.ltrim($handle, '@');
    }

    private function findTask(string $projectId, array $payload): ?Task
    {
        $taskExternalKey = trim((string) (Arr::get($payload, 'Task_ID') ?: Arr::get($payload, 'task_id') ?: Arr::get($payload, 'taskId') ?: ''));
        if ($taskExternalKey !== '') {
            return Task::query()
                ->where('project_id', $projectId)
                ->where(function ($query) use ($taskExternalKey) {
                    $query->where('external_task_key', $taskExternalKey);
                    if (Str::isUuid($taskExternalKey)) {
                        $query->orWhere('id', $taskExternalKey);
                    }
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

        $normalizedHandle = ltrim(Str::lower($handle), '@');
        $normalizedPlatform = Str::lower($platform);

        return CreatorProfile::query()
            ->where('project_id', $projectId)
            ->whereRaw("LOWER(COALESCE(platform, '')) = ?", [$normalizedPlatform])
            ->whereRaw("LOWER(REPLACE(COALESCE(handle, ''), '@', '')) = ?", [$normalizedHandle])
            ->first();
    }

    private function findMessageTemplate(string $projectId, string $templateId): ?MessageTemplate
    {
        $templateId = trim($templateId);
        if ($templateId === '') {
            return null;
        }

        $databaseId = $templateId;
        if (Str::startsWith($databaseId, 'msgdb:')) {
            $databaseId = substr($databaseId, strlen('msgdb:'));
        }

        $query = MessageTemplate::query()
            ->where('project_id', $projectId)
            ->where(function ($query) use ($templateId, $databaseId) {
                $query->where('angle_id', $templateId);

                if ($databaseId !== $templateId) {
                    $query->orWhere('angle_id', $databaseId);
                }

                if ($this->isUuid($databaseId)) {
                    $query->orWhere('id', $databaseId);
                }
            });

        return $query->first();
    }

    private function applyOutreachEventToCreatorProfile(CreatorProfile $profile, OutreachEvent $event): void
    {
        $eventType = Str::upper(trim((string) $event->event_type));
        $eventAt = $event->sent_at ?: now();
        $channel = trim((string) ($event->channel ?: $event->platform ?: $profile->platform ?: ''));
        $url = trim((string) ($event->url ?: ''));
        $advancedStates = ['replied', 'negotiating', 'accepted', 'declined', 'won', 'lost', 'archived'];

        if (in_array($eventType, $this->strictOutreachSentEventTypes(), true)) {
            if (! in_array((string) $profile->lifecycle_state, $advancedStates, true)) {
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
        } elseif (in_array($eventType, $this->strictReplyEventTypes(), true)) {
            if (! in_array((string) $profile->lifecycle_state, ['accepted', 'declined', 'won', 'lost', 'archived'], true)) {
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

    private function eventDirection(string $eventType): string
    {
        $eventType = Str::upper(trim($eventType));
        if (in_array($eventType, $this->strictReplyEventTypes(), true)) {
            return 'inbound';
        }

        return 'outbound';
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

    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            trim($value)
        );
    }
}
