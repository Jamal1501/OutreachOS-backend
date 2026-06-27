<?php

namespace Tests\Feature;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\CreatorRelationshipEvent;
use App\Models\OutreachEvent;
use App\Models\Project;
use App\Models\Task;
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

    public function test_creator_conversation_returns_logged_message_and_reply_text(): void
    {
        config(['outreach.sync.outreach' => false]);

        $workspaceId = (string) Str::uuid();
        $project = Project::query()->create([
            'workspace_id' => $workspaceId,
            'name' => 'Conversation Workspace',
            'workbook_id' => 'workspace:conversation-test',
            'status' => 'active',
        ]);

        $creator = Creator::query()->create([
            'project_id' => $project->id,
            'display_name' => 'Creator Two',
        ]);

        $profile = CreatorProfile::query()->create([
            'creator_id' => $creator->id,
            'project_id' => $project->id,
            'platform' => 'instagram',
            'handle' => '@creator_two',
            'status' => 'ENRICHED',
            'lifecycle_state' => 'enriched',
        ]);

        app(OutreachLogService::class)->appendEvent('workspace:conversation-test', [
            'Event_ID' => 'evt-conversation-sent',
            'creator_profile_id' => $profile->id,
            'Platform' => 'instagram',
            'Handle' => '@creator_two',
            'Channel' => 'instagram',
            'Event_Type' => 'DM_SENT',
            'Status' => 'COMPLETED',
            'Message_Text' => 'Hey, we have a creator idea for you.',
            'Sent_At' => '2026-06-26 10:40:30',
        ]);

        app(OutreachLogService::class)->appendEvent('workspace:conversation-test', [
            'Event_ID' => 'evt-conversation-reply',
            'creator_profile_id' => $profile->id,
            'Platform' => 'instagram',
            'Handle' => '@creator_two',
            'Channel' => 'instagram',
            'Event_Type' => 'CREATOR_REPLY',
            'Status' => 'REPLIED',
            'Message_Text' => 'Sounds interesting, what are the terms?',
            'Sent_At' => '2026-06-26 12:00:00',
        ]);

        $conversation = app(CreatorRelationshipTimelineService::class)->listConversationForCreator($profile);

        $this->assertCount(2, $conversation);
        $this->assertSame('inbound', $conversation[0]['direction']);
        $this->assertSame('Sounds interesting, what are the terms?', $conversation[0]['messageText']);
        $this->assertSame('outbound', $conversation[1]['direction']);
        $this->assertSame('Hey, we have a creator idea for you.', $conversation[1]['messageText']);
    }

    public function test_backfill_links_existing_sent_events_to_active_conversations(): void
    {
        $workspaceId = (string) Str::uuid();
        $project = Project::query()->create([
            'workspace_id' => $workspaceId,
            'name' => 'Backfill Workspace',
            'workbook_id' => 'workspace:backfill-test',
            'status' => 'active',
        ]);

        $creator = Creator::query()->create([
            'project_id' => $project->id,
            'display_name' => 'Creator Three',
        ]);

        $profile = CreatorProfile::query()->create([
            'creator_id' => $creator->id,
            'project_id' => $project->id,
            'platform' => 'instagram',
            'handle' => '@creator_three',
            'status' => 'CONTACTED',
            'lifecycle_state' => 'contacted',
        ]);

        $task = Task::query()->create([
            'project_id' => $project->id,
            'creator_profile_id' => $profile->id,
            'task_type' => 'DM_INVITE',
            'platform' => 'instagram',
            'handle' => '@creator_three',
            'status' => 'COMPLETED',
            'priority' => 'MEDIUM',
            'message_draft' => 'Older sent message from the task draft.',
            'completed_at' => '2026-06-20 10:00:00',
        ]);

        OutreachEvent::query()->create([
            'project_id' => $project->id,
            'task_id' => $task->id,
            'external_event_key' => 'evt-backfill-sent',
            'platform' => 'instagram',
            'handle' => '@creator_three',
            'channel' => 'instagram',
            'event_type' => 'DM_SENT',
            'sent_at' => '2026-06-20 10:00:00',
            'status' => 'COMPLETED',
        ]);

        $result = app(CreatorRelationshipTimelineService::class)->backfillConversationLinks((string) $project->id);
        $event = OutreachEvent::query()->where('external_event_key', 'evt-backfill-sent')->firstOrFail();
        $active = app(CreatorRelationshipTimelineService::class)->listActiveConversations($project);

        $this->assertSame(1, $result['linked']);
        $this->assertSame(1, $result['snapshotted']);
        $this->assertSame($profile->id, $event->creator_profile_id);
        $this->assertSame('Older sent message from the task draft.', $event->metadata['message_text']);
        $this->assertCount(1, $active);
        $this->assertSame($profile->id, $active[0]['creatorId']);
        $this->assertSame('Older sent message from the task draft.', $active[0]['lastMessage']['messageText']);
    }
}
