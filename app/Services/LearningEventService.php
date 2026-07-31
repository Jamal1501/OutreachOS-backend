<?php

namespace App\Services;

use App\Models\CreatorProfile;
use App\Models\LearningEvent;
use App\Models\MessageTemplate;
use App\Models\OutreachEvent;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LearningEventService
{
    public function recordOutreachEvent(OutreachEvent $event, ?Project $project = null): void
    {
        $this->safeRecord(function () use ($event, $project) {
            $event->loadMissing(['creatorProfile.creator', 'task', 'messageTemplate']);
            $project = $project ?: Project::query()->find($event->project_id);
            $task = $event->task;
            $profile = $event->creatorProfile;
            $template = $event->messageTemplate;
            $eventName = $this->normalizeEventName((string) $event->event_type);

            LearningEvent::query()->updateOrCreate(
                [
                    'source_type' => 'outreach_events',
                    'source_id' => (string) $event->id,
                    'event_name' => $eventName,
                ],
                [
                    'workspace_id' => $project?->workspace_id,
                    'project_id' => $project?->id ?: $event->project_id,
                    'project_key' => $project?->workbook_id,
                    'event_group' => $this->eventGroup($eventName),
                    'occurred_at' => $event->sent_at ?: $event->created_at,
                    'actor_user_id' => $this->actorUserId(),
                    'creator_profile_id' => $event->creator_profile_id,
                    'task_id' => $event->task_id,
                    'message_template_id' => $event->message_template_id,
                    'platform' => $event->platform,
                    'handle' => $event->handle,
                    'channel' => $event->channel,
                    'outcome_label' => $this->outcomeLabel($eventName, $event->status),
                    'status' => $event->status,
                    'creator_snapshot' => $this->creatorSnapshot($profile),
                    'task_snapshot' => $this->taskSnapshot($task),
                    'template_snapshot' => $this->templateSnapshot($template),
                    'message_snapshot' => $this->messageSnapshot($task, $template),
                    'context' => [
                        'source' => 'outreach_log',
                        'event_type' => $event->event_type,
                        'notes' => $event->notes,
                        'url_present' => trim((string) $event->url) !== '',
                    ],
                    'metadata' => [
                        'outreach_event_metadata' => $event->metadata ?? [],
                    ],
                ],
            );
        });
    }

    public function recordRoiEvent(array $event): void
    {
        $this->safeRecord(function () use ($event) {
            $eventType = $this->normalizeEventName('roi_'.(string) ($event['event_type'] ?? 'event'));

            LearningEvent::query()->updateOrCreate(
                [
                    'source_type' => 'roi_events',
                    'source_id' => (string) ($event['id'] ?? ''),
                    'event_name' => $eventType,
                ],
                [
                    'workspace_id' => $event['workspace_id'] ?? null,
                    'project_key' => (string) ($event['project_id'] ?? ''),
                    'event_group' => 'outcome',
                    'occurred_at' => now(),
                    'actor_user_id' => $this->actorUserId(),
                    'platform' => $event['platform'] ?? null,
                    'handle' => $event['creator_handle'] ?? null,
                    'outcome_label' => (string) ($event['event_type'] ?? ''),
                    'status' => 'captured',
                    'context' => [
                        'amount' => $event['amount'] ?? null,
                        'event_date' => $event['event_date'] ?? null,
                    ],
                    'metadata' => [
                        'roi_metadata' => $event['metadata'] ?? [],
                    ],
                ],
            );
        });
    }

    private function safeRecord(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning('learning event write failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function normalizeEventName(string $value): string
    {
        $normalized = Str::lower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?: 'event';

        return trim($normalized, '_') ?: 'event';
    }

    private function eventGroup(string $eventName): string
    {
        if (Str::contains($eventName, ['reply', 'accepted', 'won', 'lost', 'declined'])) {
            return 'outcome';
        }

        if (Str::contains($eventName, ['sent', 'invite', 'followup', 'email', 'dm'])) {
            return 'message';
        }

        if (Str::contains($eventName, ['task', 'snoozed', 'skipped', 'completed'])) {
            return 'task';
        }

        return 'activity';
    }

    private function outcomeLabel(string $eventName, ?string $status): ?string
    {
        if (Str::contains($eventName, 'won')) {
            return 'won';
        }
        if (Str::contains($eventName, 'accepted')) {
            return 'accepted';
        }
        if (Str::contains($eventName, 'reply')) {
            return 'replied';
        }
        if (Str::contains($eventName, 'lost')) {
            return 'lost';
        }
        if (Str::contains($eventName, 'declined')) {
            return 'declined';
        }

        return $status ? Str::lower($status) : null;
    }

    private function creatorSnapshot(?CreatorProfile $profile): ?array
    {
        if (! $profile) {
            return null;
        }

        return [
            'id' => (string) $profile->id,
            'creator_id' => (string) $profile->creator_id,
            'platform' => $profile->platform,
            'handle' => $profile->handle,
            'status' => $profile->status,
            'lifecycle_state' => $profile->lifecycle_state,
            'followers_count' => $profile->followers_count,
            'engagement_rate_pct' => $profile->engagement_rate_pct,
            'value_score' => $profile->value_score,
            'value_bar' => $profile->value_bar,
            'accepted_flag' => $profile->accepted_flag,
            'dm_sent_at' => optional($profile->dm_sent_at)?->toIso8601String(),
            'responded_at' => optional($profile->responded_at)?->toIso8601String(),
            'last_task_outcome' => $profile->last_task_outcome,
            'source_provider' => $profile->source_provider,
            'source_reference' => $profile->source_reference,
            'source_metadata' => $profile->source_metadata,
        ];
    }

    private function taskSnapshot(?Task $task): ?array
    {
        if (! $task) {
            return null;
        }

        return [
            'id' => (string) $task->id,
            'external_task_key' => $task->external_task_key,
            'task_type' => $task->task_type,
            'priority' => $task->priority,
            'status' => $task->status,
            'completion_outcome' => $task->completion_outcome,
            'skip_reason' => $task->skip_reason,
            'follow_up_count' => $task->follow_up_count,
            'actionable_channel' => $task->actionable_channel,
            'external_channel' => $task->external_channel,
            'completed_at' => optional($task->completed_at)?->toIso8601String(),
            'metadata' => $task->metadata,
        ];
    }

    private function templateSnapshot(?MessageTemplate $template): ?array
    {
        if (! $template) {
            return null;
        }

        return [
            'id' => (string) $template->id,
            'angle_id' => $template->angle_id,
            'platform' => $template->platform,
            'niche' => $template->niche,
            'stage' => $template->stage,
            'copy' => $template->copy,
            'notes' => $template->notes,
            'psychological_trigger' => $template->psychological_trigger,
            'metadata' => $template->metadata,
            'updated_at' => optional($template->updated_at)?->toIso8601String(),
        ];
    }

    private function messageSnapshot(?Task $task, ?MessageTemplate $template): ?array
    {
        $draft = trim((string) ($task?->message_draft ?? ''));
        $templateCopy = trim((string) ($template?->copy ?? ''));

        if ($draft === '' && $templateCopy === '') {
            return null;
        }

        return [
            'final_message' => $draft !== '' ? $draft : null,
            'template_copy' => $templateCopy !== '' ? $templateCopy : null,
            'used_ai_or_manual_draft' => $draft !== '',
            'message_length' => $draft !== '' ? mb_strlen($draft) : null,
        ];
    }

    private function actorUserId(): ?string
    {
        $value = request()?->attributes->get('supabase_user_id') ?: request()?->attributes->get('auth_user_id');

        return $value ? (string) $value : null;
    }
}
