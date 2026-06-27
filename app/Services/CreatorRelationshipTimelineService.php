<?php

namespace App\Services;

use App\Models\CreatorProfile;
use App\Models\CreatorRelationshipEvent;
use App\Models\OutreachEvent;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreatorRelationshipTimelineService
{
    public function recordOutreachEvent(OutreachEvent $event, Project $project): void
    {
        if (!$event->creator_profile_id || !$project->workspace_id) {
            return;
        }

        $this->safeRecord(function () use ($event, $project) {
            $event->loadMissing(['task', 'messageTemplate']);
            $eventType = $this->normalizeEventType((string) $event->event_type);
            $occurredAt = $event->sent_at ?: $event->created_at ?: now();

            CreatorRelationshipEvent::query()->updateOrCreate(
                [
                    'workspace_id' => (string) $project->workspace_id,
                    'source_type' => 'outreach_event',
                    'source_id' => (string) $event->id,
                    'event_type' => $eventType,
                ],
                [
                    'project_id' => $project->id,
                    'creator_profile_id' => $event->creator_profile_id,
                    'outreach_event_id' => $event->id,
                    'task_id' => $event->task_id,
                    'channel' => $event->channel ?: $event->platform,
                    'title' => $this->titleForOutreachEvent($eventType, $event),
                    'description' => $this->descriptionForOutreachEvent($event),
                    'occurred_at' => $occurredAt,
                    'actor_user_id' => $this->actorUserId(),
                    'metadata' => [
                        'status' => $event->status,
                        'platform' => $event->platform,
                        'handle' => $event->handle,
                        'url_present' => trim((string) $event->url) !== '',
                        'template_id' => $event->message_template_id,
                        'task_type' => $event->task?->task_type,
                        'source' => 'outreach_log',
                    ],
                ],
            );
        });
    }

    public function listForCreator(CreatorProfile $profile, string $workspaceId, int $limit = 30): Collection
    {
        $limit = max(1, min($limit, 100));

        return CreatorRelationshipEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('project_id', $profile->project_id)
            ->where('creator_profile_id', $profile->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get([
                'id',
                'event_type',
                'channel',
                'title',
                'description',
                'occurred_at',
                'created_at',
                'source_type',
                'source_id',
                'metadata',
            ])
            ->map(fn (CreatorRelationshipEvent $event) => $this->toApiItem($event));
    }

    public function listConversationForCreator(CreatorProfile $profile, int $limit = 30): Collection
    {
        $limit = max(1, min($limit, 50));

        return OutreachEvent::query()
            ->with(['task:id,message_draft'])
            ->where('project_id', $profile->project_id)
            ->where('creator_profile_id', $profile->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->limit(max($limit * 2, 20))
            ->get([
                'id',
                'task_id',
                'message_template_id',
                'platform',
                'channel',
                'event_type',
                'sender_account',
                'sent_at',
                'status',
                'url',
                'notes',
                'metadata',
                'created_at',
            ])
            ->map(fn (OutreachEvent $event) => $this->toConversationItem($event))
            ->filter(fn (?array $item) => $item !== null)
            ->take($limit)
            ->values();
    }

    public function listActiveConversations(Project $project, int $limit = 30): Collection
    {
        $limit = max(1, min($limit, 50));
        $items = collect();
        $seenProfiles = [];

        OutreachEvent::query()
            ->with(['creatorProfile.creator', 'task:id,message_draft'])
            ->where('project_id', $project->id)
            ->whereNotNull('creator_profile_id')
            ->where(function (Builder $query) {
                $query->whereIn(DB::raw("UPPER(COALESCE(event_type, ''))"), ['CREATOR_REPLY', 'CREATOR_REPLIED', 'REPLY', 'REPLY_RECEIVED', 'DM_REPLY_RECEIVED', 'EMAIL_REPLY_RECEIVED'])
                    ->orWhereRaw("UPPER(COALESCE(event_type, '')) LIKE ?", ['%SENT%'])
                    ->orWhereRaw("UPPER(COALESCE(event_type, '')) LIKE ?", ['%FOLLOWUP%']);
            })
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->limit(max($limit * 5, 100))
            ->get()
            ->each(function (OutreachEvent $event) use (&$seenProfiles, $items, $limit) {
                if ($items->count() >= $limit || !$event->creatorProfile) {
                    return;
                }

                $profileId = (string) $event->creator_profile_id;
                if (isset($seenProfiles[$profileId])) {
                    return;
                }

                $conversationItem = $this->toConversationItem($event);
                if ($conversationItem === null) {
                    return;
                }

                $profile = $event->creatorProfile;
                $items->push([
                    'creatorId' => (string) $profile->id,
                    'handle' => (string) ($profile->handle ?: $event->handle ?: ''),
                    'platform' => (string) ($profile->platform ?: $event->platform ?: ''),
                    'fullName' => (string) ($profile->creator?->display_name ?: $profile->full_name ?: ''),
                    'avatarUrl' => (string) ($profile->profile_pic_url ?: ''),
                    'lifecycleState' => (string) ($profile->lifecycle_state ?: $profile->status ?: ''),
                    'lastMessage' => $conversationItem,
                ]);
                $seenProfiles[$profileId] = true;
            });

        return $items->values();
    }

    public function backfillConversationLinks(?string $projectId = null, int $limit = 1000): array
    {
        $limit = max(1, min($limit, 10000));
        $processed = 0;
        $linked = 0;
        $snapshotted = 0;

        $query = OutreachEvent::query()
            ->with(['task:id,creator_profile_id,message_draft'])
            ->where(function (Builder $query) {
                $query->whereNull('creator_profile_id')
                    ->orWhereNull('metadata->message_text');
            })
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($projectId !== null && $projectId !== '') {
            $query->where('project_id', $projectId);
        }

        foreach ($query->get() as $event) {
            $processed++;
            $metadata = is_array($event->metadata) ? $event->metadata : [];
            $dirty = false;

            if (!$event->creator_profile_id) {
                $profile = $this->resolveProfileForEvent($event);
                if ($profile) {
                    $event->creator_profile_id = $profile->id;
                    $linked++;
                    $dirty = true;
                }
            }

            if (empty($metadata['message_text'])) {
                $messageText = trim((string) ($event->task?->message_draft ?: ''));
                if ($messageText !== '') {
                    $metadata['message_text'] = $messageText;
                    $metadata['message_direction'] = $this->conversationDirection($this->normalizeEventType((string) $event->event_type)) ?: 'outbound';
                    $event->metadata = $metadata;
                    $snapshotted++;
                    $dirty = true;
                }
            }

            if ($dirty) {
                $event->save();
            }
        }

        return [
            'processed' => $processed,
            'linked' => $linked,
            'snapshotted' => $snapshotted,
        ];
    }

    private function toApiItem(CreatorRelationshipEvent $event): array
    {
        return [
            'id' => (string) $event->id,
            'eventType' => (string) $event->event_type,
            'channel' => $event->channel,
            'title' => (string) $event->title,
            'description' => $event->description,
            'occurredAt' => optional($event->occurred_at ?: $event->created_at)?->toIso8601String(),
            'sourceType' => (string) $event->source_type,
            'sourceId' => $event->source_id,
            'metadata' => $event->metadata ?: [],
        ];
    }

    private function toConversationItem(OutreachEvent $event): ?array
    {
        $eventType = $this->normalizeEventType((string) $event->event_type);
        $direction = $this->conversationDirection($eventType);
        if ($direction === null) {
            return null;
        }

        $messageText = $this->conversationMessageText($event, $direction);
        if ($messageText === '') {
            return null;
        }

        return [
            'id' => (string) $event->id,
            'direction' => $direction,
            'eventType' => $eventType,
            'channel' => $event->channel ?: $event->platform,
            'sender' => $direction === 'outbound' ? ($event->sender_account ?: 'Team') : 'Creator',
            'messageText' => $messageText,
            'occurredAt' => optional($event->sent_at ?: $event->created_at)?->toIso8601String(),
            'status' => $event->status,
            'url' => $event->url,
        ];
    }

    private function conversationDirection(string $eventType): ?string
    {
        if (Str::contains($eventType, ['reply', 'replied'])) {
            return 'inbound';
        }

        if (Str::contains($eventType, ['sent', 'outreach', 'dm', 'email', 'followup'])) {
            return 'outbound';
        }

        return null;
    }

    private function conversationMessageText(OutreachEvent $event, string $direction): string
    {
        $metadata = is_array($event->metadata) ? $event->metadata : [];
        $metadataText = trim((string) ($metadata['message_text'] ?? ''));
        if ($metadataText !== '') {
            return $metadataText;
        }

        if ($direction === 'outbound') {
            return trim((string) ($event->task?->message_draft ?: ''));
        }

        $notes = trim((string) ($event->notes ?: ''));
        if ($notes === '') {
            return '';
        }

        if (preg_match('/Reply text:\s*([\s\S]*?)(?:\nOperator note:|$)/i', $notes, $match)) {
            return trim((string) ($match[1] ?? ''));
        }

        return $notes;
    }

    private function resolveProfileForEvent(OutreachEvent $event): ?CreatorProfile
    {
        if ($event->task?->creator_profile_id) {
            return CreatorProfile::query()
                ->where('project_id', $event->project_id)
                ->where('id', $event->task->creator_profile_id)
                ->first();
        }

        $platform = Str::lower((string) ($event->platform ?: ''));
        $handle = Str::lower(ltrim((string) ($event->handle ?: ''), '@'));
        if ($platform === '' || $handle === '') {
            return null;
        }

        return CreatorProfile::query()
            ->where('project_id', $event->project_id)
            ->whereRaw("LOWER(COALESCE(platform, '')) = ?", [$platform])
            ->whereRaw("LOWER(REPLACE(COALESCE(handle, ''), '@', '')) = ?", [$handle])
            ->first();
    }

    private function normalizeEventType(string $value): string
    {
        $normalized = Str::lower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?: 'event';

        return trim($normalized, '_') ?: 'event';
    }

    private function titleForOutreachEvent(string $eventType, OutreachEvent $event): string
    {
        if (Str::contains($eventType, ['reply', 'replied'])) {
            return 'Creator replied';
        }

        if (Str::contains($eventType, ['won', 'accepted'])) {
            return 'Creator accepted';
        }

        if (Str::contains($eventType, ['lost', 'declined'])) {
            return 'Creator declined';
        }

        if (Str::contains($eventType, ['sent', 'outreach', 'dm', 'email', 'followup'])) {
            $channel = trim((string) ($event->channel ?: $event->platform));
            return $channel !== '' ? sprintf('%s outreach sent', Str::title($channel)) : 'Outreach sent';
        }

        if ($eventType === 'state_changed') {
            return 'Lifecycle updated';
        }

        return Str::headline($eventType);
    }

    private function descriptionForOutreachEvent(OutreachEvent $event): ?string
    {
        $parts = [];

        if ($event->status) {
            $parts[] = 'Status: ' . $event->status;
        }

        if ($event->notes) {
            $parts[] = trim((string) $event->notes);
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    private function actorUserId(): ?string
    {
        $value = request()?->attributes->get('supabase_user_id') ?: request()?->attributes->get('auth_user_id');

        return $value ? (string) $value : null;
    }

    private function safeRecord(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning('creator relationship event write failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
