<?php

namespace Tests\Feature;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\LearningEvent;
use App\Models\MessageTemplate;
use App\Models\OutreachEvent;
use App\Models\Project;
use App\Models\Task;
use App\Services\OutreachLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearningEventDualWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_outreach_log_still_writes_original_event_and_adds_learning_snapshot(): void
    {
        config(['outreach.sync.outreach' => false]);

        $project = Project::query()->create([
            'workspace_id' => (string) Str::uuid(),
            'name' => 'Learning Workspace',
            'workbook_id' => 'workspace:learning-test',
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
            'followers_count' => 12000,
            'engagement_rate_pct' => 4.25,
            'value_score' => 81,
        ]);

        $template = MessageTemplate::query()->create([
            'project_id' => $project->id,
            'angle_id' => 'cold-angle-a',
            'platform' => 'instagram',
            'stage' => 'cold_invite',
            'copy' => 'Original template copy',
        ]);

        $task = Task::query()->create([
            'project_id' => $project->id,
            'creator_profile_id' => $profile->id,
            'message_template_id' => $template->id,
            'external_task_key' => 'task-learning-1',
            'platform' => 'instagram',
            'handle' => '@creator_one',
            'task_type' => 'DM_INVITE',
            'priority' => 'HIGH',
            'status' => 'COMPLETED',
            'completion_outcome' => 'sent',
            'message_draft' => 'Final sent message snapshot',
        ]);

        app(OutreachLogService::class)->appendEvent('workspace:learning-test', [
            'Event_ID' => 'evt-learning-1',
            'Task_ID' => $task->external_task_key,
            'Platform' => 'instagram',
            'Handle' => '@creator_one',
            'Channel' => 'instagram',
            'Event_Type' => 'DM_SENT',
            'Template_ID' => $template->angle_id,
            'Status' => 'COMPLETED',
        ]);

        $this->assertDatabaseHas('outreach_events', [
            'project_id' => $project->id,
            'external_event_key' => 'evt-learning-1',
            'task_id' => $task->id,
            'message_template_id' => $template->id,
        ]);

        $event = OutreachEvent::query()->where('external_event_key', 'evt-learning-1')->firstOrFail();
        $learningEvent = LearningEvent::query()
            ->where('source_type', 'outreach_events')
            ->where('source_id', $event->id)
            ->where('event_name', 'dm_sent')
            ->firstOrFail();

        $this->assertSame($project->workspace_id, $learningEvent->workspace_id);
        $this->assertSame($profile->id, $learningEvent->creator_profile_id);
        $this->assertSame($task->id, $learningEvent->task_id);
        $this->assertSame($template->id, $learningEvent->message_template_id);
        $this->assertSame('message', $learningEvent->event_group);
        $this->assertSame('Final sent message snapshot', $learningEvent->message_snapshot['final_message']);
        $this->assertSame(12000, $learningEvent->creator_snapshot['followers_count']);
        $this->assertSame('Original template copy', $learningEvent->template_snapshot['copy']);
    }
}
