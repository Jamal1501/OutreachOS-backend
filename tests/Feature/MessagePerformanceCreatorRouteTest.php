<?php

namespace Tests\Feature;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\MessagePerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MessagePerformanceCreatorRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_crmdb_route_identifier_is_resolved_before_querying_the_uuid_column(): void
    {
        $workspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Performance workspace',
            'slug' => 'performance-'.Str::lower(Str::random(8)),
            'plan_id' => 'free',
            'settings' => [],
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Performance project',
            'workbook_id' => 'workspace:performance',
            'status' => 'active',
        ]);
        $creator = Creator::query()->create([
            'project_id' => $project->id,
            'display_name' => 'Route test creator',
        ]);
        $profile = CreatorProfile::query()->create([
            'creator_id' => $creator->id,
            'project_id' => $project->id,
            'platform' => 'instagram',
            'handle' => '@route-test',
            'status' => 'qualified',
        ]);

        $result = app(MessagePerformanceService::class)->summaryForProject(
            (string) $project->id,
            ['creatorProfileId' => 'crmdb:'.$profile->id],
            collect(),
        );

        $this->assertSame('creator_profile', $result['target']['targetSource']);
        $this->assertSame('instagram', $result['target']['platform']);
    }

    public function test_invalid_creator_route_identifier_is_ignored_without_a_database_error(): void
    {
        $result = app(MessagePerformanceService::class)->summaryForProject(
            '999',
            ['creatorProfileId' => 'crmdb:not-a-uuid'],
            collect(),
        );

        $this->assertSame('crmdb:not-a-uuid', $result['target']['creatorProfileId']);
        $this->assertSame('', $result['target']['targetSource']);
    }
}
