<?php

namespace App\Services;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\CreatorRelationshipEvent;
use App\Models\CrmImportBatch;
use App\Models\OutreachEvent;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CrmImportBatchService
{
    public function __construct(
        private TaskQueueService $taskQueue,
    ) {}

    public function list(string $workspaceId, int $projectId): array
    {
        return CrmImportBatch::query()
            ->where('workspace_id', $workspaceId)
            ->where('project_id', $projectId)
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (CrmImportBatch $batch) => $this->toArray($batch))
            ->values()
            ->all();
    }

    public function activate(CrmImportBatch $batch, string $sheetId, ?string $assignedUserId = null): array
    {
        if ($batch->status === 'rolled_back') {
            throw new RuntimeException('This import has already been rolled back.');
        }
        if ($batch->status === 'activated') {
            throw new RuntimeException('This imported workflow has already been activated.');
        }

        $profileIds = $batch->items()->pluck('creator_profile_id')->filter()->map(fn ($id) => (string) $id)->values()->all();
        if ($profileIds === []) {
            throw new RuntimeException('This import does not contain any creator profiles to activate.');
        }

        DB::transaction(function () use ($batch, $profileIds, $assignedUserId): void {
            $profiles = CreatorProfile::query()->whereIn('id', $profileIds)->lockForUpdate()->get();
            foreach ($profiles as $profile) {
                $state = (array) ($profile->automation_state ?? []);
                if (empty($state['migration_hold'])) {
                    $profile->workflow_paused_at = null;
                }
                if ($assignedUserId !== null && $assignedUserId !== '') {
                    $profile->assigned_user_id = $assignedUserId;
                }
                $profile->save();
            }

            $batch->status = 'activating';
            $batch->activated_at = now();
            $batch->save();
        });

        $heldProfiles = CreatorProfile::query()->whereIn('id', $profileIds)->whereNotNull('workflow_paused_at')->count();

        try {
            $result = $this->taskQueue->generateInitialTasks($sheetId, [
                'profileIds' => $profileIds,
                'limit' => 50,
                'importBatchId' => (string) $batch->id,
                'assignedUserId' => $assignedUserId,
            ]);
        } catch (\Throwable $exception) {
            CreatorProfile::query()->whereIn('id', $profileIds)->update(['workflow_paused_at' => now()]);
            $batch->status = 'imported_paused';
            $batch->activated_at = null;
            $batch->save();
            throw $exception;
        }

        $summary = array_merge((array) ($batch->summary ?? []), [
            'taskGeneration' => $result,
            'activatedProfiles' => count($profileIds) - $heldProfiles,
            'heldForReviewProfiles' => $heldProfiles,
        ]);
        $batch->status = 'activated';
        $batch->summary = $summary;
        $batch->save();

        return [
            'batch' => $this->toArray($batch->fresh()),
            'taskGeneration' => $result,
        ];
    }

    public function rollback(CrmImportBatch $batch): array
    {
        if ($batch->status === 'rolled_back') {
            return ['batch' => $this->toArray($batch), 'restored' => 0, 'removed' => 0];
        }

        $items = $batch->items()->oldest()->get();
        $profileIds = $items->pluck('creator_profile_id')->filter()->map(fn ($id) => (string) $id)->values()->all();
        $this->assertRollbackIsSafe($batch, $profileIds);

        $restored = 0;
        $removed = 0;

        DB::transaction(function () use ($batch, $items, $profileIds, &$restored, &$removed): void {
            $batchTaskIds = Task::query()->where('import_batch_id', $batch->id)->pluck('id')->all();
            $importedEvents = OutreachEvent::query()
                ->whereIn('creator_profile_id', $profileIds)
                ->get()
                ->filter(fn (OutreachEvent $event) => (string) (($event->metadata ?? [])['import_batch_id'] ?? '') === (string) $batch->id
                    || ($event->task_id && in_array($event->task_id, $batchTaskIds, true))
                );

            if ($importedEvents->isNotEmpty()) {
                CreatorRelationshipEvent::query()
                    ->where('source_type', 'outreach_event')
                    ->whereIn('source_id', $importedEvents->pluck('id')->all())
                    ->delete();
                OutreachEvent::query()->whereIn('id', $importedEvents->pluck('id')->all())->delete();
            }

            Task::query()->where('import_batch_id', $batch->id)->delete();

            foreach ($items->reverse() as $item) {
                if ($item->action === 'history_only') {
                    continue;
                }

                $profile = $item->creator_profile_id ? CreatorProfile::query()->find($item->creator_profile_id) : null;
                $creator = $item->creator_id ? Creator::query()->find($item->creator_id) : null;

                if ($item->action === 'created') {
                    if ($profile) {
                        $profile->delete();
                        $removed++;
                    }
                    if ($creator) {
                        if (is_array($item->creator_before)) {
                            $creator->forceFill($item->creator_before)->save();
                        } elseif (! $creator->profiles()->exists()) {
                            $creator->delete();
                        }
                    }

                    continue;
                }

                if ($creator && is_array($item->creator_before)) {
                    $creator->forceFill($item->creator_before)->save();
                }
                if ($profile && is_array($item->profile_before)) {
                    $profile->forceFill($item->profile_before)->save();
                }
                $restored++;
            }

            $batch->status = 'rolled_back';
            $batch->rolled_back_at = now();
            $batch->save();
        });

        return [
            'batch' => $this->toArray($batch->fresh()),
            'restored' => $restored,
            'removed' => $removed,
        ];
    }

    public function resumeHeld(CrmImportBatch $batch, string $sheetId, int $days = 3): array
    {
        if ($batch->status !== 'activated') {
            throw new RuntimeException('Activate the imported workflow before scheduling held creators.');
        }

        $days = max(1, min(90, $days));
        $profileIds = $batch->items()->pluck('creator_profile_id')->filter()->all();
        $resumed = 0;

        DB::transaction(function () use ($profileIds, $days, &$resumed): void {
            $profiles = CreatorProfile::query()
                ->whereIn('id', $profileIds)
                ->whereNotNull('workflow_paused_at')
                ->lockForUpdate()
                ->get();

            foreach ($profiles as $profile) {
                $state = (array) ($profile->automation_state ?? []);
                if (empty($state['migration_hold'])) {
                    continue;
                }

                $lifecycle = (string) ($state['imported_workflow_state'] ?? $profile->lifecycle_state ?? '');
                $dueAt = now()->addDays($days);
                if (in_array($lifecycle, ['contacted', 'follow_up'], true)) {
                    $profile->follow_up_needed = true;
                    $profile->follow_up_due_at = $dueAt;
                    $profile->next_action_at = $dueAt;
                } elseif (in_array($lifecycle, ['negotiating', 'accepted'], true)) {
                    $profile->next_action_at = $dueAt;
                }

                unset($state['migration_hold'], $state['migration_hold_reason']);
                $state['migration_resumed_at'] = now()->toIso8601String();
                $profile->automation_state = $state;
                $profile->workflow_paused_at = null;
                $profile->save();
                $resumed++;
            }
        });

        $result = $this->taskQueue->generateInitialTasks($sheetId, [
            'profileIds' => $profileIds,
            'limit' => 50,
            'importBatchId' => (string) $batch->id,
        ]);
        $summary = (array) ($batch->summary ?? []);
        $summary['heldForReviewProfiles'] = max(0, (int) ($summary['heldForReviewProfiles'] ?? 0) - $resumed);
        $summary['scheduledHeldProfiles'] = (int) ($summary['scheduledHeldProfiles'] ?? 0) + $resumed;
        $batch->summary = $summary;
        $batch->save();

        return [
            'batch' => $this->toArray($batch->fresh()),
            'resumed' => $resumed,
            'taskGeneration' => $result,
        ];
    }

    public function toArray(CrmImportBatch $batch): array
    {
        return [
            'id' => (string) $batch->id,
            'filename' => (string) $batch->original_filename,
            'status' => (string) $batch->status,
            'rowCount' => (int) $batch->row_count,
            'summary' => $batch->summary ?: [],
            'settings' => $batch->settings ?: [],
            'createdAt' => optional($batch->created_at)?->toIso8601String(),
            'activatedAt' => optional($batch->activated_at)?->toIso8601String(),
            'rolledBackAt' => optional($batch->rolled_back_at)?->toIso8601String(),
        ];
    }

    private function assertRollbackIsSafe(CrmImportBatch $batch, array $profileIds): void
    {
        if ($profileIds === []) {
            return;
        }

        $supersededImportExists = CreatorProfile::query()
            ->whereIn('id', $profileIds)
            ->whereNotNull('import_batch_id')
            ->where('import_batch_id', '!=', $batch->id)
            ->exists();
        if ($supersededImportExists) {
            throw new RuntimeException('This import can no longer be rolled back because one or more creators were changed by a newer import.');
        }

        $completedTaskExists = Task::query()
            ->where('import_batch_id', $batch->id)
            ->whereIn('status', ['COMPLETED', 'DONE'])
            ->exists();
        if ($completedTaskExists) {
            throw new RuntimeException('This import can no longer be rolled back because work has already been completed.');
        }

        $batchTaskIds = Task::query()->where('import_batch_id', $batch->id)->pluck('id')->all();
        $hasNewActivity = OutreachEvent::query()
            ->whereIn('creator_profile_id', $profileIds)
            ->where('created_at', '>', $batch->created_at)
            ->get()
            ->contains(fn (OutreachEvent $event) => (string) (($event->metadata ?? [])['import_batch_id'] ?? '') !== (string) $batch->id
                && ! ($event->task_id && in_array($event->task_id, $batchTaskIds, true))
            );
        if ($hasNewActivity) {
            throw new RuntimeException('This import can no longer be rolled back because new outreach activity has been recorded.');
        }
    }
}
