<?php

namespace Tests\Feature;

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
