<?php

namespace Tests\Feature;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\DuplicateLink;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DuplicateLinkApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_duplicate_links_inside_the_active_workspace_only(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('owner');
        $this->fakeSupabaseUser($user);

        $response = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/crm/duplicate-links', [
                'projectId' => 'workspace:agency-client-a',
                'duplicates' => [[
                    'creatorA' => ['handle' => '@creator_a', 'platform' => 'instagram'],
                    'creatorB' => ['handle' => 'creator_b', 'platform' => 'tiktok'],
                    'confidence' => 88,
                    'signals' => ['email_match', 'bio_overlap'],
                ]],
            ]);

        $response->assertCreated();

        $projectId = (string) Project::query()
            ->where('workspace_id', $workspace->id)
            ->where('workbook_id', 'workspace:agency-client-a')
            ->value('id');

        $this->assertDatabaseHas('duplicate_links', [
            'workspace_id' => $workspace->id,
            'project_id' => $projectId,
            'creator_a_handle' => 'creator_a',
            'creator_b_handle' => 'creator_b',
            'status' => 'pending',
        ]);
    }

    public function test_scan_resolves_a_legacy_workspace_slug_without_querying_bigint_with_it(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('owner');
        $this->fakeSupabaseUser($user);
        Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Workspace project',
            'workbook_id' => 'workspace:actual-data-key',
            'status' => 'active',
        ]);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/crm/duplicate-links/scan', [
                'projectId' => $workspace->slug,
                'limit' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('summary.scanned', 0)
            ->assertJsonPath('summary.matches', 0);
    }

    public function test_it_does_not_list_duplicate_links_from_another_workspace(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('owner');
        [$otherUser, $otherWorkspace] = $this->createMemberWorkspace('owner');
        $this->fakeSupabaseUser($user);

        DuplicateLink::query()->create([
            'workspace_id' => $workspace->id,
            'project_id' => 'workspace:own',
            'creator_a_handle' => 'visible_a',
            'creator_a_platform' => 'instagram',
            'creator_b_handle' => 'visible_b',
            'creator_b_platform' => 'tiktok',
            'confidence' => 91,
            'match_signals' => ['email_match'],
            'status' => 'pending',
        ]);

        DuplicateLink::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'project_id' => 'workspace:other',
            'creator_a_handle' => 'hidden_a',
            'creator_a_platform' => 'instagram',
            'creator_b_handle' => 'hidden_b',
            'creator_b_platform' => 'tiktok',
            'confidence' => 91,
            'match_signals' => ['email_match'],
            'status' => 'pending',
        ]);

        $response = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/crm/duplicate-links');

        $response->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.creator_a_handle', 'visible_a');
    }

    public function test_it_blocks_updates_for_duplicate_links_outside_the_active_workspace(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('owner');
        [$otherUser, $otherWorkspace] = $this->createMemberWorkspace('owner');
        $this->fakeSupabaseUser($user);

        $foreignLink = DuplicateLink::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'project_id' => 'workspace:other',
            'creator_a_handle' => 'hidden_a',
            'creator_a_platform' => 'instagram',
            'creator_b_handle' => 'hidden_b',
            'creator_b_platform' => 'tiktok',
            'confidence' => 91,
            'match_signals' => ['email_match'],
            'status' => 'pending',
        ]);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->putJson('/api/crm/duplicate-links/'.$foreignLink->id, ['status' => 'merged'])
            ->assertNotFound();

        $this->assertDatabaseHas('duplicate_links', [
            'id' => $foreignLink->id,
            'status' => 'pending',
        ]);
    }

    public function test_it_does_not_resolve_creator_ids_from_a_foreign_workspace_project(): void
    {
        [$user, $workspace] = $this->createMemberWorkspace('owner');
        [, $otherWorkspace] = $this->createMemberWorkspace('owner');
        $this->fakeSupabaseUser($user);

        $foreignProject = Project::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Foreign project',
            'workbook_id' => 'workspace:foreign',
            'status' => 'active',
        ]);
        $foreignCreator = Creator::query()->create([
            'project_id' => $foreignProject->id,
            'display_name' => 'Foreign creator',
        ]);
        CreatorProfile::query()->create([
            'creator_id' => $foreignCreator->id,
            'project_id' => $foreignProject->id,
            'platform' => 'instagram',
            'handle' => 'foreign_creator',
        ]);
        DuplicateLink::query()->create([
            'workspace_id' => $workspace->id,
            'project_id' => (string) $foreignProject->id,
            'creator_a_handle' => 'foreign_creator',
            'creator_a_platform' => 'instagram',
            'creator_b_handle' => 'local_candidate',
            'creator_b_platform' => 'tiktok',
            'confidence' => 95,
            'status' => 'pending',
        ]);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/crm/duplicate-links')
            ->assertOk()
            ->assertJsonPath('items.0.creator_a_id', null);
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
            'settings' => ['workspaceDataKey' => 'workspace:test'],
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
