<?php

namespace Tests\Feature;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\CreatorRelationshipEvent;
use App\Models\OutreachEvent;
use App\Models\Project;
use App\Services\CreatorRelationshipTimelineService;
use App\Services\OutreachLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreatorRelationshipTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_outreach_log_records_idempotent_workspace_scoped_relationship_event(): void
    {
        config(['outreach.sync.outreach' => false]);

        $workspaceId = (string) Str::uuid();
        $project = Project::query()->create([
            'workspace_id' => $workspaceId,
            'name' => 'Relationship Workspace',
            'workbook_id' => 'workspace:relationship-test',
            'status' => 'active',
        ]);

        $creator = Creator::query()->create([
            'project_id' => $project->id,
            'display_name' => 'Creator One',
        ]);

        $profile = CreatorProfile::query()->create([
            'creator_id' => $creator->id,
            'project_id' => $project->id,
            'platform' => 'instagram',
            'handle' => '@creator_one',
            'status' => 'ENRICHED',
            'lifecycle_state' => 'enriched',
        ]);

        $payload = [
            'Event_ID' => 'evt-relationship-1',
            'creator_profile_id' => $profile->id,
            'Platform' => 'instagram',
            'Handle' => '@creator_one',
            'Channel' => 'instagram',
            'Event_Type' => 'DM_SENT',
            'Status' => 'COMPLETED',
            'Notes' => 'Marked as sent from outreach',
        ];

        app(OutreachLogService::class)->appendEvent('workspace:relationship-test', $payload);
        app(OutreachLogService::class)->appendEvent('workspace:relationship-test', $payload);

        $outreachEvent = OutreachEvent::query()->where('external_event_key', 'evt-relationship-1')->firstOrFail();
        $this->assertSame(1, CreatorRelationshipEvent::query()->where('outreach_event_id', $outreachEvent->id)->count());

        $relationshipEvent = CreatorRelationshipEvent::query()->where('outreach_event_id', $outreachEvent->id)->firstOrFail();
        $this->assertSame($workspaceId, $relationshipEvent->workspace_id);
        $this->assertSame($project->id, $relationshipEvent->project_id);
        $this->assertSame($profile->id, $relationshipEvent->creator_profile_id);
        $this->assertSame('dm_sent', $relationshipEvent->event_type);
        $this->assertSame('Instagram outreach sent', $relationshipEvent->title);

        $timeline = app(CreatorRelationshipTimelineService::class)->listForCreator($profile, $workspaceId);
        $this->assertCount(1, $timeline);
        $this->assertSame($relationshipEvent->id, $timeline->first()['id']);

        $foreignTimeline = app(CreatorRelationshipTimelineService::class)->listForCreator($profile, (string) Str::uuid());
        $this->assertCount(0, $foreignTimeline);
    }
}
