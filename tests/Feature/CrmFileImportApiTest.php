<?php

namespace Tests\Feature;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\OutreachEvent;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmFileImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_import_creators_from_csv_into_workspace_crm(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('owner');
        $this->fakeSupabaseUser($user);

        $csv = implode("\n", [
            'Platform,Handle,Name,Contact_Email,Followers,Engagement_Rate_%,Status,Value_Score',
            'instagram,@csv_creator,CSV Creator,csv@example.test,"12,345",4.7,ENRICHED,88',
        ]);

        $response = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->withHeader('Accept', 'application/json')
            ->post('/api/crm/import/creators', [
                'file' => UploadedFile::fake()->createWithContent('creators.csv', $csv),
            ]);

        $response->assertOk()
            ->assertJsonPath('summary.rowsRead', 1)
            ->assertJsonPath('summary.createdProfiles', 1)
            ->assertJsonPath('summary.skipped', 0);

        $this->assertDatabaseHas('projects', [
            'workspace_id' => $workspace->id,
            'workbook_id' => 'workspace:test-import',
        ]);
        $this->assertDatabaseHas('creators', [
            'display_name' => 'CSV Creator',
            'primary_email' => 'csv@example.test',
        ]);
        $this->assertDatabaseHas('creator_profiles', [
            'platform' => 'instagram',
            'handle' => '@csv_creator',
            'followers_count' => 12345,
            'value_score' => 88,
            'source_provider' => 'file_upload',
        ]);
    }

    public function test_owner_can_preview_and_import_custom_column_mapping(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('owner');
        $this->fakeSupabaseUser($user);

        $csv = implode("\n", [
            'Network,Creator Username,Creator Name,Business Email,Audience Size,Vertical',
            'instagram,custom_creator,Custom Creator,custom@example.test,9876,Fitness',
        ]);

        $preview = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->withHeader('Accept', 'application/json')
            ->post('/api/crm/import/creators/preview', [
                'file' => UploadedFile::fake()->createWithContent('custom-creators.csv', $csv),
            ]);

        $preview->assertOk()
            ->assertJsonPath('preview.rowsRead', 1)
            ->assertJsonPath('preview.suggestedMapping.platform', 'Network')
            ->assertJsonPath('preview.suggestedMapping.handle', 'Creator Username')
            ->assertJsonPath('preview.suggestedMapping.email', 'Business Email')
            ->assertJsonPath('preview.suggestedMapping.followers', 'Audience Size');

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->withHeader('Accept', 'application/json')
            ->post('/api/crm/import/creators', [
                'file' => UploadedFile::fake()->createWithContent('custom-creators.csv', $csv),
                'mapping' => json_encode([
                    'platform' => 'Network',
                    'handle' => 'Creator Username',
                    'name' => 'Creator Name',
                    'email' => 'Business Email',
                    'followers' => 'Audience Size',
                    'niche' => 'Vertical',
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertOk()
            ->assertJsonPath('summary.createdProfiles', 1);

        $this->assertDatabaseHas('creators', [
            'display_name' => 'Custom Creator',
            'primary_email' => 'custom@example.test',
            'niche_category' => 'Fitness',
        ]);
        $this->assertDatabaseHas('creator_profiles', [
            'platform' => 'instagram',
            'handle' => '@custom_creator',
            'followers_count' => 9876,
            'source_provider' => 'file_upload',
        ]);
    }

    public function test_workspace_member_cannot_import_creator_files(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('member');
        $this->fakeSupabaseUser($user);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->withHeader('Accept', 'application/json')
            ->post('/api/crm/import/creators', [
                'file' => UploadedFile::fake()->createWithContent('creators.csv', "Platform,Handle\ninstagram,@blocked"),
            ])
            ->assertForbidden();
    }

    public function test_profile_url_infers_platform_and_compact_numbers_are_parsed(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('owner');
        $this->fakeSupabaseUser($user);
        $csv = implode("\n", [
            'Instagram URL,Creator Name,Followers,Engagement Rate',
            'instagram.com/Creator.Name/?hl=en,Creator Name,12.3k,"4,7%"',
        ]);

        $preview = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/crm/import/creators/preview', [
                'file' => UploadedFile::fake()->createWithContent('instagram-list.csv', $csv),
            ])
            ->assertOk()
            ->assertJsonPath('preview.suggestedMapping.profile_url', 'Instagram URL');

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/crm/import/creators', [
                'file' => UploadedFile::fake()->createWithContent('instagram-list.csv', $csv),
                'mapping' => json_encode($preview->json('preview.suggestedMapping'), JSON_THROW_ON_ERROR),
            ])
            ->assertOk()
            ->assertJsonPath('summary.createdProfiles', 1)
            ->assertJsonPath('summary.errorCount', 0);

        $this->assertDatabaseHas('creator_profiles', [
            'platform' => 'instagram',
            'handle' => '@creator.name',
            'followers_count' => 12300,
            'engagement_rate_pct' => 4.7,
        ]);
    }

    public function test_default_platform_and_case_insensitive_reimport_update_the_existing_identity(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('owner');
        $this->fakeSupabaseUser($user);

        foreach ([
            ['Handle,Name,Email', '@CreatorName,Creator Name,old@example.test'],
            ['Handle,Name,Email', '@creatorname,Creator Name,new@example.test'],
        ] as $index => $lines) {
            $this->withToken('valid-token')
                ->withHeader('X-Workspace-Id', $workspace->id)
                ->post('/api/crm/import/creators', [
                    'file' => UploadedFile::fake()->createWithContent('reimport-'.$index.'.csv', implode("\n", $lines)),
                    'defaultPlatform' => 'instagram',
                ])
                ->assertOk();
        }

        $this->assertSame(1, CreatorProfile::query()->count());
        $this->assertSame(1, Creator::query()->count());
        $this->assertDatabaseHas('creator_profiles', ['handle' => '@creatorname']);
        $this->assertDatabaseHas('creators', ['primary_email' => 'new@example.test']);
    }

    public function test_import_returns_row_level_errors_without_hiding_valid_rows(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('owner');
        $this->fakeSupabaseUser($user);
        $csv = implode("\n", [
            'Platform,Handle,Name',
            'instagram,@valid_creator,Valid Creator',
            'unknown,@invalid_creator,Invalid Platform',
            'instagram,,Missing Handle',
        ]);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/crm/import/creators', [
                'file' => UploadedFile::fake()->createWithContent('mixed.csv', $csv),
            ])
            ->assertOk()
            ->assertJsonPath('summary.createdProfiles', 1)
            ->assertJsonPath('summary.skipped', 2)
            ->assertJsonPath('summary.errorCount', 2)
            ->assertJsonPath('summary.errors.0.rowNumber', 3)
            ->assertJsonPath('summary.errors.1.rowNumber', 4)
            ->assertJsonPath('summary.errorsTruncated', false);
    }

    public function test_empty_and_duplicate_header_files_return_clear_validation_errors(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('owner');
        $this->fakeSupabaseUser($user);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/crm/import/creators/preview', [
                'file' => UploadedFile::fake()->createWithContent('empty.csv', ''),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The CSV file is empty. Add a header row and at least one creator.');

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/crm/import/creators/preview', [
                'file' => UploadedFile::fake()->createWithContent('duplicates.csv', "Creator Handle,creator-handle\n@one,@two"),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Duplicate CSV columns were found after normalization: creator_handle. Rename them and try again.');
    }

    public function test_import_can_create_the_first_task_batch_and_continue_the_outreach_lifecycle(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('owner');
        $this->fakeSupabaseUser($user);
        $csv = "Handle,Name,Email\n@workflow_creator,Workflow Creator,workflow@example.test";

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/crm/import/creators', [
                'file' => UploadedFile::fake()->createWithContent('workflow.csv', $csv),
                'defaultPlatform' => 'instagram',
                'createInitialTasks' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('summary.createdProfiles', 1)
            ->assertJsonPath('summary.taskGeneration.created', 1);

        $task = Task::query()->where('handle', '@workflow_creator')->firstOrFail();
        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/tasks/'.$task->id.'/complete', [
                'status' => 'COMPLETED',
                'outcome' => 'sent',
                'actionType' => $task->task_type,
                'externalChannel' => $task->actionable_channel ?: 'instagram',
                'notes' => 'Completed during migration workflow test.',
            ])
            ->assertOk();

        $this->assertTrue(OutreachEvent::query()->where('creator_profile_id', $task->creator_profile_id)->exists());
        $this->assertNotSame('imported', CreatorProfile::query()->findOrFail($task->creator_profile_id)->lifecycle_state);
    }

    public function test_existing_workflow_stages_seed_the_correct_profile_state(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('owner');
        $this->fakeSupabaseUser($user);
        $csv = implode("\n", [
            'Platform,Handle,Stage,Last Contacted,Last Reply,Next Follow Up',
            'IG,@contacted_creator,Email sent,2026-07-01,,',
            'Insta,@replied_creator,Interested,2026-07-01,2026-07-02,',
            'Instagram Reels,@followup_creator,Follow up,2026-07-01,,2026-07-03',
        ]);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/crm/import/creators', [
                'file' => UploadedFile::fake()->createWithContent('active-workflow.csv', $csv),
                'createInitialTasks' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('summary.createdProfiles', 3);

        $contacted = CreatorProfile::query()->where('handle', '@contacted_creator')->firstOrFail();
        $replied = CreatorProfile::query()->where('handle', '@replied_creator')->firstOrFail();
        $followUp = CreatorProfile::query()->where('handle', '@followup_creator')->firstOrFail();

        $this->assertSame('contacted', $contacted->lifecycle_state);
        $this->assertNotNull($contacted->dm_sent_at);
        $this->assertFalse(Task::query()->where('creator_profile_id', $contacted->id)->exists());
        $this->assertSame('replied', $replied->lifecycle_state);
        $this->assertNotNull($replied->responded_at);
        $this->assertDatabaseHas('tasks', ['creator_profile_id' => $replied->id, 'task_type' => 'REVIEW_CREATOR']);
        $this->assertSame('contacted', $followUp->lifecycle_state);
        $this->assertNotNull($followUp->follow_up_due_at);
        $this->assertTrue(Task::query()->where('creator_profile_id', $followUp->id)->exists());
    }

    private function createMemberWorkspace(string $role): array
    {
        $user = User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => 'Test User',
            'email' => Str::random(8).'@example.test',
            'password' => 'password',
        ]);

        $workspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Workspace',
            'slug' => 'workspace-'.Str::random(8),
            'owner_id' => $user->supabase_user_id,
            'plan_id' => 'free',
            'settings' => ['workspaceDataKey' => 'workspace:test-import'],
        ]);

        WorkspaceMember::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $user->supabase_user_id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
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
