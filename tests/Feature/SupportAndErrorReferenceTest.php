<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientCreditsException;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\SupportController;
use App\Mail\SupportRequestMail;
use App\Models\SupportRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SupportAndErrorReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_request_is_delivered_with_a_reference_and_minimal_audit_record(): void
    {
        Mail::fake();
        config(['support.inbox_email' => 'support@example.test']);
        [$user, $workspace] = $this->workspaceFixture();
        $request = Request::create('/api/support/requests', 'POST', [
            'category' => 'technical_problem',
            'subject' => 'Discovery did not finish',
            'message' => 'The discovery remained on the enrichment step for more than twenty minutes.',
            'page' => 'https://www.socialcore.app/discover',
        ]);
        $request->attributes->set('workspace_id', $workspace->id);
        $request->attributes->set('supabase_user_id', $user->supabase_user_id);

        $response = app(SupportController::class)->store($request);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertMatchesRegularExpression('/^SUP-[A-Z0-9]{10}$/', $payload['data']['reference']);
        Mail::assertSent(SupportRequestMail::class, fn (SupportRequestMail $mail) => $mail->hasTo('support@example.test'));
        $this->assertDatabaseHas('support_requests', [
            'reference' => $payload['data']['reference'],
            'workspace_id' => $workspace->id,
            'page' => 'https://www.socialcore.app/discover',
            'status' => 'sent',
            'delivery_attempts' => 1,
        ]);
        $this->assertDatabaseHas('workspace_audit_events', [
            'workspace_id' => $workspace->id,
            'event_type' => 'support_request_submitted',
            'subject_id' => $payload['data']['reference'],
        ]);
    }

    public function test_incident_banner_is_only_visible_when_enabled_with_a_message(): void
    {
        config([
            'support.incident_banner.enabled' => true,
            'support.incident_banner.severity' => 'critical',
            'support.incident_banner.message' => 'Discovery is temporarily delayed.',
        ]);

        $response = app(SupportController::class)->banner();

        $this->assertTrue($response->getData(true)['data']['enabled']);
        $this->assertSame('critical', $response->getData(true)['data']['severity']);
    }

    public function test_database_banner_state_overrides_environment_fallback(): void
    {
        config([
            'support.incident_banner.enabled' => true,
            'support.incident_banner.message' => 'Old environment incident.',
        ]);
        DB::table('platform_incident_banners')->insert([
            'id' => (string) Str::uuid(),
            'enabled' => false,
            'severity' => 'warning',
            'message' => 'Resolved incident.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = app(SupportController::class)->banner()->getData(true);

        $this->assertFalse($payload['data']['enabled']);
        $this->assertNull($payload['data']['message']);
    }

    public function test_operator_can_list_and_resolve_persisted_support_requests(): void
    {
        [$user, $workspace] = $this->workspaceFixture();
        $ticket = SupportRequest::query()->create([
            'reference' => 'SUP-OPER123456',
            'workspace_id' => $workspace->id,
            'user_id' => $user->supabase_user_id,
            'email' => $user->email,
            'category' => 'technical_problem',
            'subject' => 'Cannot complete review',
            'message' => 'The duplicate review returns after every refresh.',
            'page' => 'https://www.socialcore.app/duplicates',
            'status' => 'sent',
            'ticket_status' => 'open',
        ]);

        $listRequest = Request::create('/api/operations/support-requests', 'GET', ['status' => 'open']);
        $list = app(OperationsController::class)->supportRequests($listRequest)->getData(true);
        $this->assertSame($ticket->reference, $list['data']['items'][0]['reference']);
        $this->assertSame($workspace->name, $list['data']['items'][0]['workspaceName']);

        $updateRequest = Request::create('/api/operations/support-requests/'.$ticket->id, 'PATCH', ['status' => 'resolved']);
        $updateRequest->attributes->set('supabase_user_id', 'operator-user');
        app(OperationsController::class)->updateSupportRequest($updateRequest, (string) $ticket->id);

        $this->assertDatabaseHas('support_requests', [
            'id' => $ticket->id,
            'ticket_status' => 'resolved',
            'updated_by_operator_id' => 'operator-user',
        ]);
    }

    public function test_unhandled_api_errors_return_a_customer_reference_without_internal_details(): void
    {
        Route::get('/api/testing/error-reference', fn () => throw new RuntimeException('private database failure details'));

        $response = $this->getJson('/api/testing/error-reference');

        $response->assertStatus(500)
            ->assertJsonMissing(['message' => 'private database failure details'])
            ->assertJsonStructure(['message', 'errorReference']);
        $this->assertMatchesRegularExpression('/^ERR-[A-Z0-9]{10}$/', (string) $response->json('errorReference'));
    }

    public function test_insufficient_credits_is_a_customer_action_not_an_internal_error(): void
    {
        Route::get('/api/testing/insufficient-credits', fn () => throw new InsufficientCreditsException(
            'Not enough credits available for this action.',
            ['bucket' => 'ai', 'required' => 3, 'available' => 1],
        ));

        $this->getJson('/api/testing/insufficient-credits')
            ->assertStatus(402)
            ->assertJsonPath('code', 'insufficient_credits')
            ->assertJsonPath('creditBucket', 'ai')
            ->assertJsonPath('requiredCredits', 3)
            ->assertJsonPath('availableCredits', 1)
            ->assertJsonMissingPath('errorReference');
    }

    private function workspaceFixture(): array
    {
        $user = User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => 'Support User',
            'email' => Str::random(8).'@example.test',
            'password' => 'password',
        ]);
        $workspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Support workspace',
            'slug' => 'support-'.Str::lower(Str::random(8)),
            'owner_id' => $user->supabase_user_id,
            'plan_id' => 'free',
            'settings' => [],
        ]);
        DB::table('workspace_members')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $user->supabase_user_id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
