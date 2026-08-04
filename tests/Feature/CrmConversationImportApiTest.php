<?php

namespace Tests\Feature;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\CreatorRelationshipEvent;
use App\Models\OutreachEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmConversationImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_preview_and_import_history_without_changing_workflow(): void
    {
        [$user, $workspace, $project, $profile] = $this->createWorkspaceWithProfile('owner');
        $this->fakeSupabaseUser($user);
        $originalState = [
            'status' => $profile->status,
            'lifecycle_state' => $profile->lifecycle_state,
            'next_action_at' => optional($profile->next_action_at)?->toIso8601String(),
            'workflow_paused_at' => optional($profile->workflow_paused_at)?->toIso8601String(),
        ];
        Task::query()->create([
            'project_id' => $project->id,
            'creator_profile_id' => $profile->id,
            'task_type' => 'DM_INVITE',
            'status' => 'PENDING',
        ]);

        $csv = implode("\n", [
            'Network,Creator Username,Direction,Message Text,Timestamp,Channel,Thread URL',
            'instagram,@history_creator,sent by team,"Hello from our team",2026-07-01 10:00:00,instagram,https://instagram.com/direct/t/1',
            'instagram,@history_creator,received from creator,"Thanks, I am interested",2026-07-01 11:00:00,instagram,https://instagram.com/direct/t/1',
            'instagram,@missing,received,"Unknown creator",2026-07-01 12:00:00,instagram,',
        ]);

        $preview = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/crm/import/conversations/preview', [
                'file' => UploadedFile::fake()->createWithContent('history.csv', $csv),
            ])
            ->assertOk()
            ->assertJsonPath('preview.rowsRead', 3)
            ->assertJsonPath('preview.suggestedMapping.platform', 'Network')
            ->assertJsonPath('preview.suggestedMapping.handle', 'Creator Username')
            ->assertJsonPath('preview.suggestedMapping.message', 'Message Text')
            ->assertJsonPath('preview.suggestedMapping.sent_at', 'Timestamp');

        $response = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/crm/import/conversations', [
                'sheetId' => $project->workbook_id,
                'file' => UploadedFile::fake()->createWithContent('history.csv', $csv),
                'mapping' => json_encode($preview->json('preview.suggestedMapping'), JSON_THROW_ON_ERROR),
            ])
            ->assertOk()
            ->assertJsonPath('summary.rowsRead', 3)
            ->assertJsonPath('summary.createdEvents', 2)
            ->assertJsonPath('summary.duplicateEvents', 0)
            ->assertJsonPath('summary.matchedProfiles', 1)
            ->assertJsonPath('summary.errorCount', 1)
            ->assertJsonPath('summary.errors.0.rowNumber', 4);

        $this->assertSame(2, OutreachEvent::query()->where('creator_profile_id', $profile->id)->count());
        $this->assertSame(2, CreatorRelationshipEvent::query()->where('creator_profile_id', $profile->id)->count());
        $this->assertSame(1, Task::query()->where('creator_profile_id', $profile->id)->count());
        $freshProfile = $profile->fresh();
        $this->assertSame($originalState, [
            'status' => $freshProfile->status,
            'lifecycle_state' => $freshProfile->lifecycle_state,
            'next_action_at' => optional($freshProfile->next_action_at)?->toIso8601String(),
            'workflow_paused_at' => optional($freshProfile->workflow_paused_at)?->toIso8601String(),
        ]);
        $this->assertDatabaseHas('crm_import_batches', [
            'id' => $response->json('summary.batchId'),
            'status' => 'activated',
        ]);
        $this->assertDatabaseHas('crm_import_batch_items', [
            'batch_id' => $response->json('summary.batchId'),
            'creator_profile_id' => $profile->id,
            'action' => 'history_only',
        ]);
    }

    public function test_history_reimport_is_idempotent_and_original_batch_can_be_rolled_back(): void
    {
        [$user, $workspace, $project, $profile] = $this->createWorkspaceWithProfile('owner');
        $this->fakeSupabaseUser($user);
        $csv = "Handle,Direction,Message,Date\n@history_creator,outbound,Hello,2026-07-01T10:00:00Z";
        $payload = fn () => [
            'sheetId' => $project->workbook_id,
            'file' => UploadedFile::fake()->createWithContent('history.csv', $csv),
            'defaultPlatform' => 'instagram',
        ];

        $first = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/crm/import/conversations', $payload())
            ->assertOk()
            ->assertJsonPath('summary.createdEvents', 1);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/crm/import/conversations', $payload())
            ->assertOk()
            ->assertJsonPath('summary.createdEvents', 0)
            ->assertJsonPath('summary.duplicateEvents', 1);

        $this->assertSame(1, OutreachEvent::query()->count());

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/crm/import/batches/'.$first->json('summary.batchId').'/rollback')
            ->assertOk()
            ->assertJsonPath('result.restored', 0)
            ->assertJsonPath('result.removed', 0);

        $this->assertSame(0, OutreachEvent::query()->count());
        $this->assertSame(0, CreatorRelationshipEvent::query()->count());
        $this->assertDatabaseHas('creator_profiles', ['id' => $profile->id, 'lifecycle_state' => 'contacted']);
    }

    public function test_email_can_match_one_existing_creator_and_other_workspace_is_not_visible(): void
    {
        [$user, $workspace, $project, $profile] = $this->createWorkspaceWithProfile('owner');
        [, , $otherProject, $otherProfile] = $this->createWorkspaceWithProfile('owner', '@other', 'shared@example.test');
        $profile->creator->update(['primary_email' => 'shared@example.test']);
        $this->fakeSupabaseUser($user);

        $csv = "Email,Direction,Message,Date\nshared@example.test,inbound,Workspace scoped reply,2026-07-02 09:00:00";
        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/crm/import/conversations', [
                'sheetId' => $project->workbook_id,
                'file' => UploadedFile::fake()->createWithContent('email-history.csv', $csv),
            ])
            ->assertOk()
            ->assertJsonPath('summary.createdEvents', 1);

        $this->assertDatabaseHas('outreach_events', ['project_id' => $project->id, 'creator_profile_id' => $profile->id]);
        $this->assertDatabaseMissing('outreach_events', ['project_id' => $otherProject->id, 'creator_profile_id' => $otherProfile->id]);
    }

    public function test_workspace_member_cannot_import_conversation_history(): void
    {
        [$user, $workspace, $project] = $this->createWorkspaceWithProfile('member');
        $this->fakeSupabaseUser($user);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/crm/import/conversations', [
                'sheetId' => $project->workbook_id,
                'file' => UploadedFile::fake()->createWithContent('history.csv', "Handle,Direction,Message,Date\n@history_creator,sent,Hello,2026-07-01"),
                'defaultPlatform' => 'instagram',
            ])
            ->assertForbidden();
    }

    private function createWorkspaceWithProfile(string $role, string $handle = '@history_creator', string $email = 'history@example.test'): array
    {
        $user = User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => 'Test User',
            'email' => Str::random(8).'@example.test',
            'password' => 'password',
        ]);
        $workspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'History Workspace',
            'slug' => 'history-'.Str::random(8),
            'owner_id' => $user->supabase_user_id,
            'plan_id' => 'free',
            'settings' => ['workspaceDataKey' => 'workspace:'.Str::uuid()],
        ]);
        WorkspaceMember::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $user->supabase_user_id,
            'role' => $role,
            'joined_at' => now(),
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'History Project',
            'workbook_id' => (string) ($workspace->settings['workspaceDataKey'] ?? 'workspace:'.Str::uuid()),
        ]);
        $creator = Creator::query()->create([
            'project_id' => $project->id,
            'external_identity_key' => 'instagram:'.Str::lower($handle),
            'display_name' => 'History Creator',
            'primary_email' => $email,
        ]);
        $profile = CreatorProfile::query()->create([
            'project_id' => $project->id,
            'creator_id' => $creator->id,
            'platform' => 'instagram',
            'handle' => $handle,
            'status' => 'CONTACTED',
            'lifecycle_state' => 'contacted',
            'next_action_at' => now()->addDay(),
        ]);

        return [$user, $workspace, $project, $profile];
    }

    private function fakeSupabaseUser(User $user): void
    {
        config([
            'services.supabase.url' => 'https://supabase.example.test',
            'services.supabase.service_role_key' => 'service-role-key',
        ]);
        Http::fake([
            'supabase.example.test/auth/v1/user' => Http::response([
                'id' => $user->supabase_user_id,
                'email' => $user->email,
                'email_confirmed_at' => now()->toIso8601String(),
                'user_metadata' => ['full_name' => $user->name],
            ], 200),
        ]);
    }
}
