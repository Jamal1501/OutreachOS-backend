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

        $export = $this->workspaceExport($workspace->id);

        $this->assertSame('workspace_customer_data', $export['exportType']);
        $this->assertCount(1, $export['projects']);
        $this->assertCount(1, $export['data']['workspace_members']);
        $this->assertCount(1, $export['data']['workspace_audit_events']);
    }

    public function test_workspace_export_supports_a_workspace_without_projects(): void
    {
        [, $workspace] = $this->workspaceFixture();

        $export = $this->workspaceExport($workspace->id);

        $this->assertSame([], $export['projects']);
        $this->assertSame([], $export['data']['creators']);
        $this->assertSame([], $export['data']['tasks']);
        $this->assertCount(1, $export['data']['workspace_members']);
    }

    public function test_workspace_export_includes_the_shared_billing_account(): void
    {
        [$user, $workspace] = $this->workspaceFixture();
        $billingAccountId = (string) Str::uuid();
        DB::table('billing_accounts')->insert([
            'id' => $billingAccountId,
            'owner_user_id' => $user->supabase_user_id,
            'primary_workspace_id' => $workspace->id,
            'name' => 'Lifecycle billing',
            'plan_id' => 'free',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $workspace->billing_account_id = $billingAccountId;
        $workspace->save();

        $export = $this->workspaceExport($workspace->id);

        $this->assertCount(1, $export['data']['billing_accounts']);
        $this->assertSame($billingAccountId, $export['data']['billing_accounts'][0]['id']);
    }

    public function test_workspace_export_is_emitted_in_incremental_chunks(): void
    {
        [, $workspace] = $this->workspaceFixture();
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Large export project',
            'workbook_id' => 'workspace:large-export-test',
            'status' => 'active',
        ]);
        $payload = str_repeat('export-data-', 800);
        for ($index = 0; $index < 20; $index++) {
            DB::table('discovery_items')->insert([
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'platform' => 'instagram',
                'raw_payload' => json_encode(['payload' => $payload, 'index' => $index]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $chunks = [];
        app(DataLifecycleService::class)->streamWorkspaceExport(
            $workspace->id,
            static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
        );
        $export = json_decode(implode('', $chunks), true, flags: JSON_THROW_ON_ERROR);

        $this->assertGreaterThan(1, count($chunks));
        $this->assertCount(20, $export['data']['discovery_items']);
        $this->assertLessThan(131072, max(array_map('strlen', $chunks)));
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

    public function test_account_restoration_cancels_account_and_owned_workspace_deletions(): void
    {
        [$user, $workspace] = $this->workspaceFixture();
        $workspace->settings = [
            'deletedAt' => now()->toIso8601String(),
            'deletedBy' => $user->supabase_user_id,
            'archivedAt' => now()->toIso8601String(),
            'archivedBy' => $user->supabase_user_id,
            'workspaceDataKey' => 'workspace:restored',
        ];
        $workspace->save();
        $service = app(DataLifecycleService::class);
        $service->scheduleAccountDeletion($user->supabase_user_id);

        $service->cancelAccountDeletion($user->supabase_user_id);

        $this->assertDatabaseHas('data_deletion_requests', [
            'type' => 'account',
            'user_id' => $user->supabase_user_id,
            'status' => 'canceled',
        ]);
        $this->assertDatabaseHas('data_deletion_requests', [
            'type' => 'workspace',
            'workspace_id' => $workspace->id,
            'status' => 'canceled',
        ]);
        $settings = (array) Workspace::query()->findOrFail($workspace->id)->settings;
        $this->assertArrayNotHasKey('deletedAt', $settings);
        $this->assertArrayNotHasKey('archivedAt', $settings);
        $this->assertSame('workspace:restored', $settings['workspaceDataKey']);
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

    private function workspaceExport(string $workspaceId): array
    {
        $content = '';
        app(DataLifecycleService::class)->streamWorkspaceExport(
            $workspaceId,
            static function (string $chunk) use (&$content): void {
                $content .= $chunk;
            },
        );

        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
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
