<?php

namespace App\Services;

use App\Models\CreatorProfile;
use App\Models\CreatorRelationshipEvent;
use App\Models\OutreachEvent;
use App\Models\Project;
use Illuminate\Support\Collection;
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
