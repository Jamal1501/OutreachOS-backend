<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskOwnershipApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_can_claim_and_release_their_own_tasks_but_cannot_take_another_members_task(): void
    {
        [$owner, $memberA, $memberB, $workspace, $project] = $this->fixture();
        $task = $this->task($project);

        $this->fakeSupabaseUsers([
            'member-a-token' => $memberA,
            'member-b-token' => $memberB,
        ]);
        $this->withToken('member-a-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->putJson('/api/tasks/'.$task->external_task_key.'/assignment', [
                'sheetId' => $project->workbook_id,
                'assignedUserId' => $memberA->supabase_user_id,
            ])
            ->assertOk()
            ->assertJsonPath('assignedUserId', $memberA->supabase_user_id);

        $this->withToken('member-b-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->putJson('/api/tasks/'.$task->external_task_key.'/assignment', [
                'sheetId' => $project->workbook_id,
                'assignedUserId' => $memberB->supabase_user_id,
            ])
            ->assertForbidden();
        $this->assertSame($memberA->supabase_user_id, $task->fresh()->assigned_user_id);

        $this->withToken('member-b-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/tasks/'.$task->external_task_key.'/snooze', [
                'sheetId' => $project->workbook_id,
                'until' => now()->addDay()->toIso8601String(),
            ])
            ->assertForbidden();

        $this->withToken('member-a-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->putJson('/api/tasks/'.$task->external_task_key.'/assignment', [
                'sheetId' => $project->workbook_id,
                'assignedUserId' => null,
            ])
            ->assertOk()
            ->assertJsonPath('assignedUserId', null);
        $this->assertNull($task->fresh()->assigned_user_id);
    }

    public function test_owner_can_view_team_workload_and_members_cannot(): void
    {
        [$owner, $memberA, $memberB, $workspace, $project] = $this->fixture();
        $this->task($project, $memberA->supabase_user_id, 'PENDING', now()->subDay());
        $this->task($project, $memberA->supabase_user_id, 'COMPLETED', now()->subDays(2), now()->subDay());
        $this->task($project);

        $this->fakeSupabaseUsers([
            'owner-token' => $owner,
            'member-b-token' => $memberB,
        ]);
        $response = $this->withToken('owner-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/tasks/team-summary?sheetId='.urlencode($project->workbook_id))
            ->assertOk()
            ->assertJsonPath('unassignedOpen', 1);

        $memberSummary = collect($response->json('members'))->firstWhere('assignedUserId', $memberA->supabase_user_id);
        $this->assertSame(1, $memberSummary['open']);
        $this->assertSame(1, $memberSummary['overdue']);
        $this->assertSame(1, $memberSummary['completed30Days']);

        $this->withToken('member-b-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/tasks/team-summary?sheetId='.urlencode($project->workbook_id))
            ->assertForbidden();
    }

    private function fixture(): array
    {
        $owner = $this->user('owner');
        $memberA = $this->user('member-a');
        $memberB = $this->user('member-b');
        $workspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Task ownership workspace',
            'slug' => 'task-ownership-'.Str::lower(Str::random(8)),
            'owner_id' => $owner->supabase_user_id,
            'plan_id' => 'free',
            'settings' => ['workspaceDataKey' => 'workspace:task-ownership'],
        ]);
        foreach ([[$owner, 'owner'], [$memberA, 'member'], [$memberB, 'member']] as [$user, $role]) {
            WorkspaceMember::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'user_id' => $user->supabase_user_id,
                'role' => $role,
                'joined_at' => now(),
            ]);
        }
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Task ownership project',
            'workbook_id' => 'workspace:task-ownership',
            'status' => 'active',
        ]);

        return [$owner, $memberA, $memberB, $workspace, $project];
    }

    private function user(string $name): User
    {
        return User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $name.'@example.test',
            'password' => 'password',
        ]);
    }

    private function task(Project $project, ?string $assignedUserId = null, string $status = 'PENDING', mixed $dueAt = null, mixed $completedAt = null): Task
    {
        return Task::query()->create([
            'project_id' => $project->id,
            'external_task_key' => (string) Str::uuid(),
            'task_type' => 'DM_INVITE',
            'priority' => 'MEDIUM',
            'status' => $status,
            'due_at' => $dueAt ?? now()->addDay(),
            'completed_at' => $completedAt,
            'assigned_user_id' => $assignedUserId,
        ]);
    }

    /** @param array<string, User> $usersByToken */
    private function fakeSupabaseUsers(array $usersByToken): void
    {
        config([
            'services.supabase.url' => 'https://supabase.example.test',
            'services.supabase.service_role_key' => 'service-role-key',
        ]);
        Http::fake(function ($request) use ($usersByToken) {
            $authorization = (string) (($request->header('Authorization')[0] ?? ''));
            $token = Str::after($authorization, 'Bearer ');
            $user = $usersByToken[$token] ?? null;

            return $user
                ? Http::response([
                    'id' => $user->supabase_user_id,
                    'email' => $user->email,
                    'email_confirmed_at' => now()->toIso8601String(),
                    'user_metadata' => ['full_name' => $user->name],
                ], 200)
                : Http::response([], 401);
        });
    }
}
