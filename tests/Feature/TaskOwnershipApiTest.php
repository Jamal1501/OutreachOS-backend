<?php

namespace Tests\Feature;

use App\Models\Creator;
use App\Models\CreatorProfile;
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
        $profile = $this->profile($project);
        $task = $this->task($project, creatorProfileId: $profile->id);
        $siblingTask = $this->task($project, creatorProfileId: $profile->id);

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
            ->assertJsonPath('assignedUserId', $memberA->supabase_user_id)
            ->assertJsonCount(2, 'affectedTasks')
            ->assertJsonPath('affectedTasks.0.assignedUserId', $memberA->supabase_user_id)
            ->assertJsonPath('affectedTasks.1.assignedUserId', $memberA->supabase_user_id)
            ->assertJsonPath('ownershipCounts.mine', 2)
            ->assertJsonPath('ownershipCounts.unassigned', 0)
            ->assertJsonPath('ownershipCounts.team', 2);

        $this->withToken('member-b-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->putJson('/api/tasks/'.$task->external_task_key.'/assignment', [
                'sheetId' => $project->workbook_id,
                'assignedUserId' => $memberB->supabase_user_id,
            ])
            ->assertForbidden();
        $this->assertSame($memberA->supabase_user_id, $task->fresh()->assigned_user_id);
        $this->assertSame($memberA->supabase_user_id, $siblingTask->fresh()->assigned_user_id);
        $this->assertSame($memberA->supabase_user_id, $profile->fresh()->assigned_user_id);

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
        $this->assertNull($siblingTask->fresh()->assigned_user_id);
        $this->assertNull($profile->fresh()->assigned_user_id);
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

    public function test_task_list_returns_the_complete_workload_including_future_snoozed_tasks(): void
    {
        [$owner, $memberA, $memberB, $workspace, $project] = $this->fixture();
        $this->task($project);
        $futureTask = $this->task($project, status: 'SNOOZED');
        $futureTask->update(['snoozed_until' => now()->addMonth()]);
        $this->task($project, status: 'COMPLETED', completedAt: now());

        $this->fakeSupabaseUsers(['owner-token' => $owner]);

        $this->withToken('owner-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/tasks/list?sheetId='.urlencode($project->workbook_id))
            ->assertOk()
            ->assertJsonPath('taskCount', 3)
            ->assertJsonCount(3, 'tasks')
            ->assertJsonFragment(['status' => 'snoozed']);
    }

    public function test_member_task_list_hides_other_members_row_level_work(): void
    {
        [$owner, $memberA, $memberB, $workspace, $project] = $this->fixture();
        $mine = $this->task($project, $memberA->supabase_user_id);
        $unassigned = $this->task($project);
        $otherMembers = $this->task($project, $memberB->supabase_user_id);

        $this->fakeSupabaseUsers([
            'member-a-token' => $memberA,
            'owner-token' => $owner,
        ]);

        $memberResponse = $this->withToken('member-a-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/tasks/list?sheetId='.urlencode($project->workbook_id))
            ->assertOk()
            ->assertJsonPath('canViewTeamDetails', false)
            ->assertJsonPath('taskCount', 2);

        $memberTaskIds = collect($memberResponse->json('tasks'))->pluck('taskId');
        $this->assertTrue($memberTaskIds->contains($mine->external_task_key));
        $this->assertTrue($memberTaskIds->contains($unassigned->external_task_key));
        $this->assertFalse($memberTaskIds->contains($otherMembers->external_task_key));

        $this->withToken('owner-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/tasks/list?sheetId='.urlencode($project->workbook_id))
            ->assertOk()
            ->assertJsonPath('canViewTeamDetails', true)
            ->assertJsonPath('taskCount', 3);
    }

    public function test_dashboard_operator_view_is_scoped_to_the_signed_in_members_assignments(): void
    {
        [$owner, $memberA, $memberB, $workspace, $project] = $this->fixture();
        $profileA = $this->profile($project, $memberA->supabase_user_id);
        $profileB = $this->profile($project, $memberB->supabase_user_id);
        $profileA->update(['status' => 'APPROVED_FOR_OUTREACH', 'lifecycle_state' => 'approved_for_outreach']);
        $profileB->update(['status' => 'APPROVED_FOR_OUTREACH', 'lifecycle_state' => 'approved_for_outreach']);
        $this->task($project, $memberA->supabase_user_id, dueAt: now(), creatorProfileId: $profileA->id);
        $this->task($project, $memberB->supabase_user_id, dueAt: now(), creatorProfileId: $profileB->id);

        $this->fakeSupabaseUsers([
            'member-a-token' => $memberA,
            'member-b-token' => $memberB,
        ]);

        $memberAResponse = $this->withToken('member-a-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/operator/view?sheetId='.urlencode($project->workbook_id).'&scope=mine&range=30d')
            ->assertOk()
            ->assertJsonPath('data.viewScope', 'mine')
            ->assertJsonPath('data.metrics.tasksDueToday', 1)
            ->assertJsonPath('data.metrics.readyForOutreach', 1)
            ->assertJsonPath('data.workspaceMetrics.creatorsEnriched', 2);

        $this->assertSame([$profileA->handle], collect($memberAResponse->json('data.readyQueue'))->pluck('handle')->all());

        $memberBResponse = $this->withToken('member-b-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/operator/view?sheetId='.urlencode($project->workbook_id).'&scope=mine&range=30d')
            ->assertOk()
            ->assertJsonPath('data.viewScope', 'mine')
            ->assertJsonPath('data.metrics.tasksDueToday', 1)
            ->assertJsonPath('data.metrics.readyForOutreach', 1);

        $this->assertSame([$profileB->handle], collect($memberBResponse->json('data.readyQueue'))->pluck('handle')->all());
    }

    public function test_completed_work_is_credited_to_the_actual_member(): void
    {
        [$owner, $memberA, $memberB, $workspace, $project] = $this->fixture();
        $this->task(
            $project,
            $memberA->supabase_user_id,
            'COMPLETED',
            now()->subDay(),
            now(),
            completedByUserId: $memberB->supabase_user_id,
        );

        $this->fakeSupabaseUsers(['owner-token' => $owner]);
        $response = $this->withToken('owner-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/tasks/team-summary?sheetId='.urlencode($project->workbook_id))
            ->assertOk();

        $summary = collect($response->json('members'));
        $this->assertNull($summary->firstWhere('assignedUserId', $memberA->supabase_user_id));
        $this->assertSame(1, $summary->firstWhere('assignedUserId', $memberB->supabase_user_id)['completed30Days']);
    }

    public function test_completing_a_task_records_the_actual_actor(): void
    {
        [$owner, $memberA, $memberB, $workspace, $project] = $this->fixture();
        $task = $this->task($project, $memberA->supabase_user_id);

        $this->fakeSupabaseUsers(['owner-token' => $owner]);
        $this->withToken('owner-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/tasks/'.$task->external_task_key.'/complete', [
                'sheetId' => $project->workbook_id,
                'status' => 'COMPLETED',
                'outcome' => 'sent',
            ])
            ->assertOk();

        $this->assertSame($owner->supabase_user_id, $task->fresh()->completed_by_user_id);
    }

    public function test_member_cannot_claim_an_unassigned_task_from_another_members_creator_workflow(): void
    {
        [$owner, $memberA, $memberB, $workspace, $project] = $this->fixture();
        $profile = $this->profile($project, $memberB->supabase_user_id);
        $task = $this->task($project, creatorProfileId: $profile->id);

        $this->fakeSupabaseUsers(['member-a-token' => $memberA]);
        $this->withToken('member-a-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/tasks/'.$task->external_task_key.'/complete', [
                'sheetId' => $project->workbook_id,
                'status' => 'COMPLETED',
            ])
            ->assertForbidden();

        $this->assertSame('PENDING', $task->fresh()->status);
        $this->assertNull($task->fresh()->assigned_user_id);
    }

    public function test_owner_can_bulk_assign_unassigned_creator_workflows(): void
    {
        [$owner, $memberA, $memberB, $workspace, $project] = $this->fixture();
        $profiles = collect(range(1, 3))->map(fn () => $this->profile($project));
        foreach ($profiles as $profile) {
            $this->task($project, creatorProfileId: $profile->id);
            $this->task($project, creatorProfileId: $profile->id);
        }

        $this->fakeSupabaseUsers(['owner-token' => $owner]);
        $this->withToken('owner-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/tasks/assign-unassigned', [
                'sheetId' => $project->workbook_id,
                'assignedUserId' => $memberA->supabase_user_id,
                'limit' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('assignedWorkflows', 2)
            ->assertJsonPath('updatedTasks', 4);

        $this->assertSame(4, Task::query()->where('assigned_user_id', $memberA->supabase_user_id)->count());
        $this->assertSame(2, CreatorProfile::query()->where('assigned_user_id', $memberA->supabase_user_id)->count());
    }

    public function test_removing_a_member_releases_their_open_work(): void
    {
        [$owner, $memberA, $memberB, $workspace, $project] = $this->fixture();
        $profile = $this->profile($project, $memberA->supabase_user_id);
        $task = $this->task($project, $memberA->supabase_user_id, creatorProfileId: $profile->id);
        $membership = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $memberA->supabase_user_id)
            ->firstOrFail();

        $this->fakeSupabaseUsers(['owner-token' => $owner]);
        $this->withToken('owner-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->deleteJson('/api/workspaces/members/'.$membership->id)
            ->assertOk()
            ->assertJsonPath('releasedAssignments.tasks', 1)
            ->assertJsonPath('releasedAssignments.creators', 1);

        $this->assertNull($task->fresh()->assigned_user_id);
        $this->assertNull($profile->fresh()->assigned_user_id);
        $this->assertDatabaseMissing('workspace_members', ['id' => $membership->id]);
    }

    public function test_removing_workspace_access_also_releases_open_work(): void
    {
        [$owner, $memberA, $memberB, $workspace, $project] = $this->fixture();
        $profile = $this->profile($project, $memberA->supabase_user_id);
        $task = $this->task($project, $memberA->supabase_user_id, creatorProfileId: $profile->id);

        $this->fakeSupabaseUsers(['owner-token' => $owner]);
        $this->withToken('owner-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->putJson('/api/workspaces/members/'.$memberA->supabase_user_id.'/workspaces', [
                'workspaceIds' => [],
                'role' => 'member',
            ])
            ->assertOk();

        $this->assertNull($task->fresh()->assigned_user_id);
        $this->assertNull($profile->fresh()->assigned_user_id);
        $this->assertDatabaseMissing('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $memberA->supabase_user_id,
        ]);
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

    private function profile(Project $project, ?string $assignedUserId = null): CreatorProfile
    {
        $handle = 'creator_'.Str::lower(Str::random(8));
        $creator = Creator::query()->create([
            'project_id' => $project->id,
            'external_identity_key' => 'instagram:'.$handle,
            'display_name' => 'Task Creator',
        ]);

        return CreatorProfile::query()->create([
            'project_id' => $project->id,
            'creator_id' => $creator->id,
            'platform' => 'instagram',
            'handle' => $handle,
            'status' => 'APPROVED',
            'lifecycle_state' => 'approved',
            'assigned_user_id' => $assignedUserId,
        ]);
    }

    private function task(
        Project $project,
        ?string $assignedUserId = null,
        string $status = 'PENDING',
        mixed $dueAt = null,
        mixed $completedAt = null,
        ?string $creatorProfileId = null,
        ?string $completedByUserId = null,
    ): Task {
        return Task::query()->create([
            'project_id' => $project->id,
            'creator_profile_id' => $creatorProfileId,
            'external_task_key' => (string) Str::uuid(),
            'task_type' => 'DM_INVITE',
            'priority' => 'MEDIUM',
            'status' => $status,
            'due_at' => $dueAt ?? now()->addDay(),
            'completed_at' => $completedAt,
            'assigned_user_id' => $assignedUserId,
            'completed_by_user_id' => $completedByUserId,
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
