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

        $task = DB::transaction(function () use ($project, $taskId, $assignedUserId, $actorUserId, $canManageTeam) {
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
            }

            $task->assigned_user_id = $assignedUserId;
            $task->save();

            return $task;
        });

        return response()->json([
            'message' => $assignedUserId === $actorUserId ? 'Task claimed' : ($assignedUserId ? 'Task assigned' : 'Task unassigned'),
            'taskId' => (string) ($task->external_task_key ?: $task->id),
            'assignedUserId' => $assignedUserId,
        ]);
    }
}
