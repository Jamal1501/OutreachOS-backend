<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
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

class MessageTemplateImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_preview_map_and_import_templates_safely(): void
    {
        [$user, $workspace, $project] = $this->createWorkspace('owner');
        $this->fakeSupabaseUser($user);
        $csv = implode("\n", [
            'Template Name,Message Text,Network,Sequence Step,Vertical,Notes,Psych Trigger',
            'Gift pitch,"Hi {{first_name}}, your content is a great fit.",instagram,First Touch,Gifts,Use for cold creators,reciprocity',
            'Terms follow-up,"Would you like to discuss the terms?",instagram,Commercial chat,Gifts,Use after reply,clarity',
            ',Missing name,instagram,First Touch,Gifts,,',
        ]);

        $preview = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/messages/import/preview', [
                'file' => UploadedFile::fake()->createWithContent('templates.csv', $csv),
            ])
            ->assertOk()
            ->assertJsonPath('preview.rowsRead', 3)
            ->assertJsonPath('preview.suggestedMapping.name', 'Template Name')
            ->assertJsonPath('preview.suggestedMapping.copy', 'Message Text')
            ->assertJsonPath('preview.workflow.unknownStageCount', 1);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/messages/import', [
                'sheetId' => $project->workbook_id,
                'file' => UploadedFile::fake()->createWithContent('templates.csv', $csv),
                'mapping' => json_encode($preview->json('preview.suggestedMapping'), JSON_THROW_ON_ERROR),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Map every template stage before importing. Still unmapped: Commercial chat.');

        $response = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/messages/import', [
                'sheetId' => $project->workbook_id,
                'file' => UploadedFile::fake()->createWithContent('templates.csv', $csv),
                'mapping' => json_encode($preview->json('preview.suggestedMapping'), JSON_THROW_ON_ERROR),
                'stageMapping' => json_encode(['Commercial chat' => 'negotiation'], JSON_THROW_ON_ERROR),
            ])
            ->assertOk()
            ->assertJsonPath('summary.createdTemplates', 2)
            ->assertJsonPath('summary.updatedTemplates', 0)
            ->assertJsonPath('summary.errorCount', 1)
            ->assertJsonPath('summary.errors.0.rowNumber', 4);

        $this->assertDatabaseHas('message_templates', [
            'project_id' => $project->id,
            'angle_id' => 'Gift pitch',
            'platform' => 'instagram',
            'stage' => 'cold_invite',
            'niche' => 'Gifts',
        ]);
        $this->assertDatabaseHas('message_templates', [
            'project_id' => $project->id,
            'angle_id' => 'Terms follow-up',
            'stage' => 'negotiation',
        ]);
        $this->assertSame(0, Task::query()->count());
        $this->assertDatabaseHas('crm_import_batch_items', [
            'batch_id' => $response->json('summary.batchId'),
            'action' => 'template_created',
        ]);
        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/messages/import/batches?sheetId='.urlencode($project->workbook_id))
            ->assertOk()
            ->assertJsonCount(1, 'batches')
            ->assertJsonPath('batches.0.settings.importType', 'message_templates');
    }

    public function test_reimport_skips_duplicates_and_rollback_removes_only_imported_templates(): void
    {
        [$user, $workspace, $project] = $this->createWorkspace('owner');
        $this->fakeSupabaseUser($user);
        MessageTemplate::query()->create([
            'project_id' => $project->id,
            'angle_id' => 'Existing template',
            'platform' => 'instagram',
            'stage' => 'cold_invite',
            'copy' => 'Keep me',
        ]);
        $csv = "Name,Message,Stage\nImported template,Hello there,follow up";
        $payload = fn () => [
            'sheetId' => $project->workbook_id,
            'file' => UploadedFile::fake()->createWithContent('templates.csv', $csv),
            'defaultPlatform' => 'instagram',
        ];

        $first = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/messages/import', $payload())
            ->assertOk()
            ->assertJsonPath('summary.createdTemplates', 1);
        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/messages/import', $payload())
            ->assertOk()
            ->assertJsonPath('summary.createdTemplates', 0)
            ->assertJsonPath('summary.duplicateTemplates', 1);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/messages/import/batches/'.$first->json('summary.batchId').'/rollback', [
                'sheetId' => $project->workbook_id,
            ])
            ->assertOk()
            ->assertJsonPath('result.templatesRemoved', 1);

        $this->assertDatabaseMissing('message_templates', ['project_id' => $project->id, 'angle_id' => 'Imported template']);
        $this->assertDatabaseHas('message_templates', ['project_id' => $project->id, 'angle_id' => 'Existing template', 'copy' => 'Keep me']);
    }

    public function test_unused_existing_template_can_be_overwritten_and_restored(): void
    {
        [$user, $workspace, $project] = $this->createWorkspace('owner');
        $this->fakeSupabaseUser($user);
        $template = MessageTemplate::query()->create([
            'project_id' => $project->id,
            'angle_id' => 'Editable',
            'platform' => 'instagram',
            'stage' => 'cold_invite',
            'copy' => 'Original copy',
            'notes' => 'Original notes',
            'metadata' => ['preserved' => true],
        ]);
        $csv = "Name,Message,Stage,Notes\nEditable,Updated copy,follow_up,Updated notes";

        $response = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/messages/import', [
                'sheetId' => $project->workbook_id,
                'file' => UploadedFile::fake()->createWithContent('overwrite.csv', $csv),
                'defaultPlatform' => 'instagram',
                'overwriteExisting' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('summary.updatedTemplates', 1);
        $this->assertSame('Updated copy', $template->fresh()->copy);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/messages/import/batches/'.$response->json('summary.batchId').'/rollback', [
                'sheetId' => $project->workbook_id,
            ])
            ->assertOk()
            ->assertJsonPath('result.templatesRestored', 1);

        $restored = $template->fresh();
        $this->assertSame('Original copy', $restored->copy);
        $this->assertSame('Original notes', $restored->notes);
        $this->assertSame(['preserved' => true], $restored->metadata);
    }

    public function test_template_with_activity_is_never_overwritten(): void
    {
        [$user, $workspace, $project] = $this->createWorkspace('owner');
        $this->fakeSupabaseUser($user);
        $template = MessageTemplate::query()->create([
            'project_id' => $project->id,
            'angle_id' => 'Used template',
            'platform' => 'instagram',
            'stage' => 'cold_invite',
            'copy' => 'Original copy',
        ]);
        Task::query()->create([
            'project_id' => $project->id,
            'message_template_id' => $template->id,
            'task_type' => 'DM_INVITE',
            'status' => 'PENDING',
        ]);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/messages/import', [
                'sheetId' => $project->workbook_id,
                'file' => UploadedFile::fake()->createWithContent('used.csv', "Name,Message\nUsed template,Changed copy"),
                'defaultPlatform' => 'instagram',
                'overwriteExisting' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('summary.updatedTemplates', 0)
            ->assertJsonPath('summary.errorCount', 1);

        $this->assertSame('Original copy', $template->fresh()->copy);
    }

    public function test_rollback_refuses_to_erase_a_template_edited_after_import(): void
    {
        [$user, $workspace, $project] = $this->createWorkspace('owner');
        $this->fakeSupabaseUser($user);
        $response = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/messages/import', [
                'sheetId' => $project->workbook_id,
                'file' => UploadedFile::fake()->createWithContent('templates.csv', "Name,Message\nEdited later,Imported copy"),
                'defaultPlatform' => 'instagram',
            ])
            ->assertOk();

        MessageTemplate::query()->where('project_id', $project->id)->where('angle_id', 'Edited later')->update(['copy' => 'Manual edit']);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/messages/import/batches/'.$response->json('summary.batchId').'/rollback', [
                'sheetId' => $project->workbook_id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This template import can no longer be rolled back because an imported template was edited afterward.');

        $this->assertDatabaseHas('message_templates', ['project_id' => $project->id, 'angle_id' => 'Edited later', 'copy' => 'Manual edit']);
    }

    public function test_member_cannot_import_templates(): void
    {
        [$user, $workspace, $project] = $this->createWorkspace('member');
        $this->fakeSupabaseUser($user);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->post('/api/messages/import', [
                'sheetId' => $project->workbook_id,
                'file' => UploadedFile::fake()->createWithContent('templates.csv', "Name,Message\nBlocked,Hello"),
            ])
            ->assertForbidden();
    }

    private function createWorkspace(string $role): array
    {
        $user = User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => 'Template User',
            'email' => Str::random(8).'@example.test',
            'password' => 'password',
        ]);
        $workbookId = 'workspace:'.Str::uuid();
        $workspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Template Workspace',
            'slug' => 'template-'.Str::random(8),
            'owner_id' => $user->supabase_user_id,
            'plan_id' => 'free',
            'settings' => ['workspaceDataKey' => $workbookId],
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
            'name' => 'Template Project',
            'workbook_id' => $workbookId,
        ]);

        return [$user, $workspace, $project];
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
