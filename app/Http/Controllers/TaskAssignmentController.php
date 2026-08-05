<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\WorkspaceMember;
use App\Services\ProjectResolverService;
use App\Services\WorkspaceContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaskAssignmentController extends Controller
{
    public function __construct(
        private WorkspaceContextService $workspaceContext,
        private ProjectResolverService $projects,
    ) {}

    public function update(Request $request, string $taskId)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'assignedUserId' => ['nullable', 'string', 'max:255'],
        ]);
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $assignedUserId = trim((string) ($validated['assignedUserId'] ?? '')) ?: null;
        if ($assignedUserId && ! WorkspaceMember::query()->where('workspace_id', $workspaceId)->where('user_id', $assignedUserId)->exists()) {
            return response()->json(['message' => 'The selected assignee is not a member of this workspace.'], 422);
        }

        $workbookId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $project = $this->projects->resolveByWorkbookId($workbookId);
        $actorUserId = trim((string) $request->attributes->get('supabase_user_id'));
        $role = strtolower(trim((string) $request->attributes->get('workspace_role')));
        $canManageTeam = in_array($role, ['owner', 'admin'], true);

        [$task, $updatedTaskCount] = DB::transaction(function () use ($project, $taskId, $assignedUserId, $actorUserId, $canManageTeam) {
            $task = Task::query()
                ->where('project_id', $project->id)
                ->where(function ($query) use ($taskId) {
                    $query->where('external_task_key', $taskId);
                    if (Str::isUuid($taskId)) {
                        $query->orWhere('id', $taskId);
                    }
                })
                ->lockForUpdate()
                ->firstOrFail();

            if (! $canManageTeam) {
                $currentAssignee = trim((string) ($task->assigned_user_id ?? '')) ?: null;
                if ($assignedUserId !== null && $assignedUserId !== $actorUserId) {
                    throw new AuthorizationException('You can only claim a task for yourself.');
                }
                if ($currentAssignee !== null && $currentAssignee !== $actorUserId) {
                    throw new AuthorizationException('This task is assigned to another workspace member.');
                }
                if ($task->creator_profile_id) {
                    $profileOwnedByAnotherMember = DB::table('creator_profiles')
                        ->where('id', $task->creator_profile_id)
                        ->whereNotNull('assigned_user_id')
                        ->where('assigned_user_id', '!=', $actorUserId)
                        ->exists();
                    $taskOwnedByAnotherMember = Task::query()
                        ->where('project_id', $project->id)
                        ->where('creator_profile_id', $task->creator_profile_id)
                        ->whereNotIn('status', ['COMPLETED', 'DONE', 'SKIPPED', 'ARCHIVED'])
                        ->whereNotNull('assigned_user_id')
                        ->where('assigned_user_id', '!=', $actorUserId)
                        ->exists();
                    if ($profileOwnedByAnotherMember || $taskOwnedByAnotherMember) {
                        throw new AuthorizationException('Another workspace member owns this creator workflow.');
                    }
                }
            }
            if (in_array(strtoupper((string) $task->status), ['COMPLETED', 'DONE', 'SKIPPED', 'ARCHIVED'], true)) {
                throw ValidationException::withMessages(['task' => 'Completed tasks cannot be reassigned.']);
            }

            $assignment = [
                'assigned_user_id' => $assignedUserId,
                'assigned_by_user_id' => $assignedUserId ? $actorUserId : null,
                'assigned_at' => $assignedUserId ? now() : null,
            ];
            $task->fill($assignment)->save();

            $updatedTaskCount = 1;
            if ($task->creator_profile_id) {
                $task->creatorProfile()->update(['assigned_user_id' => $assignedUserId]);
                $updatedTaskCount = Task::query()
                    ->where('project_id', $project->id)
                    ->where('creator_profile_id', $task->creator_profile_id)
                    ->whereNotIn('status', ['COMPLETED', 'DONE', 'SKIPPED', 'ARCHIVED'])
                    ->update($assignment);
            }

            return [$task->fresh(), $updatedTaskCount];
        });

        return response()->json([
            'message' => $assignedUserId === $actorUserId ? 'Task claimed' : ($assignedUserId ? 'Task assigned' : 'Task unassigned'),
            'taskId' => (string) ($task->external_task_key ?: $task->id),
            'assignedUserId' => $assignedUserId,
            'updatedTaskCount' => $updatedTaskCount,
        ]);
    }

    public function assignUnassigned(Request $request)
    {
        $validated = $request->validate([
            'sheetId' => ['nullable', 'string'],
            'assignedUserId' => ['required', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $workspaceId = (string) $request->attributes->get('workspace_id');
        $assignedUserId = trim((string) $validated['assignedUserId']);
        if (! WorkspaceMember::query()->where('workspace_id', $workspaceId)->where('user_id', $assignedUserId)->exists()) {
            return response()->json(['message' => 'The selected assignee is not a member of this workspace.'], 422);
        }

        $workbookId = $this->workspaceContext->resolveWorkbookId($request, $validated['sheetId'] ?? null);
        $project = $this->projects->resolveByWorkbookId($workbookId);
        $actorUserId = trim((string) $request->attributes->get('supabase_user_id'));
        $limit = (int) ($validated['limit'] ?? 10);

        $result = DB::transaction(function () use ($project, $assignedUserId, $actorUserId, $limit) {
            $candidates = Task::query()
                ->where('project_id', $project->id)
                ->whereNull('assigned_user_id')
                ->whereNotIn('status', ['COMPLETED', 'DONE', 'SKIPPED', 'ARCHIVED'])
                ->orderBy('due_at')
                ->orderBy('created_at')
                ->limit(500)
                ->lockForUpdate()
                ->get();
            $assignment = [
                'assigned_user_id' => $assignedUserId,
                'assigned_by_user_id' => $actorUserId,
                'assigned_at' => now(),
            ];
            $profileIds = [];
            $standaloneTaskIds = [];
            $ownedProfileIds = Task::query()
                ->where('project_id', $project->id)
                ->whereNotNull('creator_profile_id')
                ->whereNotNull('assigned_user_id')
                ->whereNotIn('status', ['COMPLETED', 'DONE', 'SKIPPED', 'ARCHIVED'])
                ->pluck('creator_profile_id')
                ->merge(DB::table('creator_profiles')
                    ->where('project_id', $project->id)
                    ->whereNotNull('assigned_user_id')
                    ->pluck('id'))
                ->mapWithKeys(fn ($id) => [(string) $id => true]);

            foreach ($candidates as $task) {
                if (count($profileIds) + count($standaloneTaskIds) >= $limit) {
                    break;
                }
                if ($task->creator_profile_id) {
                    if ($ownedProfileIds->has((string) $task->creator_profile_id)) {
                        continue;
                    }
                    $profileIds[(string) $task->creator_profile_id] = true;
                } else {
                    $standaloneTaskIds[] = $task->id;
                }
            }

            $profileIds = array_keys($profileIds);
            $updatedTasks = 0;
            if ($profileIds !== []) {
                $updatedTasks += Task::query()
                    ->where('project_id', $project->id)
                    ->whereIn('creator_profile_id', $profileIds)
                    ->whereNull('assigned_user_id')
                    ->whereNotIn('status', ['COMPLETED', 'DONE', 'SKIPPED', 'ARCHIVED'])
                    ->update($assignment);
                DB::table('creator_profiles')
                    ->whereIn('id', $profileIds)
                    ->update(['assigned_user_id' => $assignedUserId, 'updated_at' => now()]);
            }
            if ($standaloneTaskIds !== []) {
                $updatedTasks += Task::query()->whereIn('id', $standaloneTaskIds)->update($assignment);
            }

            return [
                'assignedWorkflows' => count($profileIds) + count($standaloneTaskIds),
                'updatedTasks' => $updatedTasks,
            ];
        });

        return response()->json([
            'message' => $result['assignedWorkflows'] > 0
                ? 'Unassigned work was added to this member.'
                : 'There is no unassigned work to distribute.',
            'assignedUserId' => $assignedUserId,
            ...$result,
        ]);
    }
}
