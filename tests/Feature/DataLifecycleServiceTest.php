<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DataLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_export_contains_project_membership_and_audit_data(): void
    {
        [$user, $workspace] = $this->workspaceFixture();
        Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Export project',
            'workbook_id' => 'workspace:export-test',
            'status' => 'active',
        ]);
        DB::table('workspace_audit_events')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'actor_user_id' => $user->supabase_user_id,
            'event_type' => 'export_test',
            'created_at' => now(),
        ]);

        $export = app(DataLifecycleService::class)->exportWorkspace($workspace->id);

        $this->assertSame('workspace_customer_data', $export['exportType']);
        $this->assertCount(1, $export['projects']);
        $this->assertCount(1, $export['data']['workspace_members']);
        $this->assertCount(1, $export['data']['workspace_audit_events']);
    }

    public function test_workspace_deletion_can_be_canceled_during_recovery_window(): void
    {
        [$user, $workspace] = $this->workspaceFixture();
        $service = app(DataLifecycleService::class);

        $scheduled = $service->scheduleWorkspaceDeletion($workspace->id, $user->supabase_user_id);
        $service->cancelWorkspaceDeletion($workspace->id);

        $this->assertSame(30, $scheduled['recoveryDays']);
        $this->assertDatabaseHas('data_deletion_requests', [
            'workspace_id' => $workspace->id,
            'status' => 'canceled',
        ]);
    }

    public function test_due_workspace_deletion_purges_customer_content(): void
    {
        [$user, $workspace] = $this->workspaceFixture();
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Delete project',
            'workbook_id' => 'workspace:delete-test',
            'status' => 'active',
        ]);
        $service = app(DataLifecycleService::class);
        $service->scheduleWorkspaceDeletion($workspace->id, $user->supabase_user_id);
        DB::table('data_deletion_requests')->where('workspace_id', $workspace->id)->update(['purge_after' => now()->subMinute()]);

        $this->assertSame(1, $service->purgeDue());
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id, 'name' => 'Deleted workspace']);
        $this->assertDatabaseHas('data_deletion_requests', ['workspace_id' => $workspace->id, 'status' => 'completed']);
    }

    public function test_failed_supabase_account_deletion_remains_scheduled_for_retry(): void
    {
        $user = $this->userFixture();
        config(['services.supabase.url' => 'https://supabase.example.test', 'services.supabase.service_role_key' => 'service-key']);
        Http::fake(['supabase.example.test/*' => Http::response(['message' => 'unavailable'], 500)]);
        $service = app(DataLifecycleService::class);
        $service->scheduleAccountDeletion($user->supabase_user_id);
        DB::table('data_deletion_requests')->where('type', 'account')->update(['purge_after' => now()->subMinute()]);

        $this->assertSame(0, $service->purgeDue());
        $this->assertDatabaseHas('users', ['supabase_user_id' => $user->supabase_user_id]);
        $request = DB::table('data_deletion_requests')->where('type', 'account')->first();
        $this->assertSame('scheduled', $request->status);
        $this->assertSame(1, (int) data_get(json_decode($request->metadata, true), 'purge_attempts'));
    }

    public function test_successful_supabase_account_deletion_completes_local_purge(): void
    {
        $user = $this->userFixture();
        config(['services.supabase.url' => 'https://supabase.example.test', 'services.supabase.service_role_key' => 'service-key']);
        Http::fake(['supabase.example.test/*' => Http::response([], 204)]);
        $service = app(DataLifecycleService::class);
        $service->scheduleAccountDeletion($user->supabase_user_id);
        DB::table('data_deletion_requests')->where('type', 'account')->update(['purge_after' => now()->subMinute()]);

        $this->assertSame(1, $service->purgeDue());
        $this->assertDatabaseMissing('users', ['supabase_user_id' => $user->supabase_user_id]);
        $this->assertDatabaseHas('data_deletion_requests', ['type' => 'account', 'status' => 'completed']);
    }

    private function workspaceFixture(): array
    {
        $user = $this->userFixture();
        $workspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Lifecycle workspace',
            'slug' => 'lifecycle-'.Str::lower(Str::random(8)),
            'owner_id' => $user->supabase_user_id,
            'plan_id' => 'free',
            'settings' => [],
        ]);
        DB::table('workspace_members')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $user->supabase_user_id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }

    private function userFixture(): User
    {
        return User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => 'Lifecycle User',
            'email' => Str::random(8).'@example.test',
            'password' => 'password',
        ]);
    }
}
