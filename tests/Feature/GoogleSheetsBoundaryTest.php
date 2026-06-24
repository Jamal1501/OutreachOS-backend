<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\OutreachEvent;
use App\Models\Project;
use App\Services\GoogleSheetsService;
use App\Services\OperationalDataImportService;
use App\Services\OutreachLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoogleSheetsBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_outreach_logging_does_not_touch_google_sheets(): void
    {
        $this->mock(GoogleSheetsService::class, function ($mock) {
            $mock->shouldNotReceive('getHeaders');
            $mock->shouldNotReceive('appendAssocRows');
            $mock->shouldNotReceive('getRows');
            $mock->shouldNotReceive('updateAssocRow');
        });

        $project = Project::query()->create([
            'workspace_id' => (string) Str::uuid(),
            'name' => 'Runtime DB Workspace',
            'workbook_id' => 'workspace:runtime-db',
            'status' => 'active',
        ]);

        app(OutreachLogService::class)->appendEvent('workspace:runtime-db', [
            'Event_ID' => 'evt-runtime-db-1',
            'Platform' => 'instagram',
            'Handle' => '@runtime_creator',
            'Channel' => 'instagram',
            'Event_Type' => 'DM_SENT',
            'Status' => 'COMPLETED',
        ]);

        $this->assertDatabaseHas('outreach_events', [
            'project_id' => $project->id,
            'external_event_key' => 'evt-runtime-db-1',
        ]);
    }

    public function test_explicit_legacy_sheet_import_can_read_sheet_rows_into_database(): void
    {
        $this->mock(GoogleSheetsService::class, function ($mock) {
            $mock->shouldReceive('getRows')
                ->once()
                ->with('legacy-sheet-id', 'Creators_CRM')
                ->andReturn([[
                    '_row_number' => 2,
                    'Platform' => 'instagram',
                    'Handle' => '@imported_creator',
                    'Name' => 'Imported Creator',
                    'Contact_Email' => 'creator@example.test',
                    'Status' => 'ENRICHED',
                    'Followers' => '12345',
                    'Engagement_Rate_%' => '4.5',
                    'Value_Score' => '82',
                ]]);

            foreach ([
                'Message_Library',
                'Task_Queue',
                'Outreach_Log',
                'Instagram_Posts_Raw',
                'TikTok_Posts_Raw',
                'Instagram_Profile_Enriched',
                'TikTok_Profile_Enriched',
            ] as $sheetName) {
                $mock->shouldReceive('getRows')
                    ->once()
                    ->with('legacy-sheet-id', $sheetName)
                    ->andReturn([]);
            }
        });

        $summary = app(OperationalDataImportService::class)->importWorkbook(
            'legacy-sheet-id',
            'Imported Legacy Sheet',
        );

        $this->assertSame(1, $summary['creators']['rows_read']);
        $this->assertDatabaseHas('projects', [
            'workbook_id' => 'legacy-sheet-id',
            'name' => 'Imported Legacy Sheet',
        ]);
        $this->assertDatabaseHas('creators', [
            'display_name' => 'Imported Creator',
            'primary_email' => 'creator@example.test',
        ]);
        $this->assertDatabaseHas('creator_profiles', [
            'platform' => 'instagram',
            'handle' => '@imported_creator',
            'source_provider' => 'google_sheets',
        ]);

        $profile = CreatorProfile::query()->where('handle', '@imported_creator')->firstOrFail();
        $this->assertSame(12345, $profile->followers_count);
        $this->assertSame(82, $profile->value_score);
        $this->assertSame(0, OutreachEvent::query()->count());
    }
}
