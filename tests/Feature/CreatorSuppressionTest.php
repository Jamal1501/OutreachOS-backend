<?php

namespace Tests\Feature;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\CreatorSuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreatorSuppressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_suppression_removes_existing_profile_and_blocks_handle_and_email_variants(): void
    {
        DB::table('plans')->updateOrInsert(['id' => 'free'], [
            'name' => 'Free',
            'max_members' => 1,
            'max_creators' => 100,
            'features' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $workspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Privacy test',
            'slug' => 'privacy-test',
            'owner_id' => (string) Str::uuid(),
            'plan_id' => 'free',
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Project',
            'workbook_id' => 'workspace:privacy-test',
            'status' => 'active',
        ]);
        $creator = Creator::query()->create([
            'project_id' => $project->id,
            'external_identity_key' => 'instagram:privacy_creator',
            'display_name' => 'Privacy Creator',
            'primary_email' => 'Creator@Example.test',
        ]);
        CreatorProfile::query()->create([
            'creator_id' => $creator->id,
            'project_id' => $project->id,
            'platform' => 'instagram',
            'handle' => '@Privacy_Creator',
        ]);

        $service = app(CreatorSuppressionService::class);
        $service->suppress('instagram', 'https://instagram.com/privacy_creator/', 'creator@example.test', 'Creator requested removal', 'operator-id');

        $this->assertDatabaseMissing('creator_profiles', ['project_id' => $project->id]);
        $this->assertDatabaseMissing('creators', ['id' => $creator->id]);
        $this->assertTrue($service->isSuppressed('instagram', '@PRIVACY_CREATOR'));
        $this->assertTrue($service->isSuppressed('tiktok', 'different', 'CREATOR@example.test'));
        $this->assertFalse($service->isSuppressed('instagram', 'another_creator', 'another@example.test'));
    }

    public function test_provider_items_and_profiles_are_removed_before_processing(): void
    {
        $service = app(CreatorSuppressionService::class);
        $service->suppress('instagram', 'blocked_creator', null, 'Creator requested removal', 'operator-id');

        $items = $service->filterProviderItems('instagram', [
            ['authorMeta' => ['name' => 'blocked_creator']],
            ['ownerUsername' => 'allowed_creator'],
        ]);
        $profiles = $service->filterProfiles('instagram', [
            ['profileUrl' => 'https://instagram.com/blocked_creator'],
            ['profileUrl' => 'https://instagram.com/allowed_creator'],
        ]);

        $this->assertSame('allowed_creator', $items[0]['ownerUsername']);
        $this->assertSame('https://instagram.com/allowed_creator', $profiles[0]['profileUrl']);
    }
}
