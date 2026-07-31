<?php

namespace Tests\Feature;

use App\Exceptions\ActiveDiscoveryException;
use App\Exceptions\InsufficientCreditsException;
use App\Mail\WorkspaceInvitationMail;
use App\Models\DiscoveryRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AiGatewayService;
use App\Services\PipelineDiscoveryService;
use App\Services\WorkspaceBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class WorkspaceIsolationAndBillingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_access_a_workspace_without_membership(): void
    {
        [$user, $ownWorkspace] = $this->createWorkspaceForRole('owner');
        [, $foreignWorkspace] = $this->createWorkspaceForRole('owner');
        $this->fakeSupabaseUser($user);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $foreignWorkspace->id)
            ->getJson('/api/auth-check')
            ->assertForbidden()
            ->assertJsonPath('error', 'You do not have access to this workspace.');
    }

    public function test_workspace_members_cannot_start_billing_checkout(): void
    {
        [$user, $workspace] = $this->createWorkspaceForRole('member');
        $this->fakeSupabaseUser($user);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/billing/checkout/topup', [
                'packageId' => 'test-package',
            ])
            ->assertForbidden();
    }

    public function test_owner_can_invite_member_and_email_is_sent(): void
    {
        Mail::fake();
        [$owner, $workspace] = $this->createWorkspaceForRole('owner', maxMembers: 3);
        $this->fakeSupabaseUser($owner);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/workspaces/invitations', [
                'email' => 'teammate@example.test',
                'role' => 'member',
            ])
            ->assertCreated()
            ->assertJsonPath('data.pendingInvitations', 1);

        $this->assertDatabaseHas('workspace_invitations', [
            'workspace_id' => $workspace->id,
            'email' => 'teammate@example.test',
            'role' => 'member',
            'accepted_at' => null,
        ]);
        $this->assertDatabaseHas('workspace_audit_events', [
            'workspace_id' => $workspace->id,
            'event_type' => 'invitation_created',
        ]);
        Mail::assertSent(WorkspaceInvitationMail::class);
    }

    public function test_invited_user_is_added_to_workspace_on_authenticated_request(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner', maxMembers: 3);
        DB::table('workspace_invitations')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'email' => 'invitee@example.test',
            'role' => 'member',
            'token' => (string) Str::uuid(),
            'accepted_at' => null,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fakeSupabaseIdentity((string) Str::uuid(), 'invitee@example.test', 'Invitee User');

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/auth-check')
            ->assertOk();

        $inviteeId = DB::table('users')->where('email', 'invitee@example.test')->value('supabase_user_id');
        $this->assertNotEmpty($inviteeId);
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $inviteeId,
            'role' => 'member',
        ]);
        $this->assertNotNull(DB::table('workspace_invitations')->where('email', 'invitee@example.test')->value('accepted_at'));
    }

    public function test_admin_cannot_invite_another_admin(): void
    {
        [$admin, $workspace] = $this->createWorkspaceForRole('admin', maxMembers: 3);
        $this->fakeSupabaseUser($admin);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/workspaces/invitations', [
                'email' => 'new-admin@example.test',
                'role' => 'admin',
            ])
            ->assertForbidden();
    }

    public function test_owner_can_transfer_workspace_ownership_to_existing_member(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner', maxMembers: 3);
        $target = User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => 'Future Owner',
            'email' => 'future-owner@example.test',
            'password' => 'password',
        ]);
        WorkspaceMember::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $target->supabase_user_id,
            'role' => 'member',
            'joined_at' => now(),
        ]);
        $this->fakeSupabaseUser($owner);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/workspaces/current/transfer-owner', [
                'targetUserId' => $target->supabase_user_id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $target->supabase_user_id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $owner->supabase_user_id,
            'role' => 'admin',
        ]);
        $this->assertSame($target->supabase_user_id, Workspace::query()->find($workspace->id)->owner_id);
    }

    public function test_legacy_app_key_cannot_access_workspace_scoped_routes(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');

        config([
            'services.app_security.key' => 'legacy-test-key',
            'services.app_security.allow_legacy_key' => true,
        ]);

        $this->withHeader('X-APP-KEY', 'legacy-test-key')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/auth-check')
            ->assertForbidden()
            ->assertJsonPath('error', 'Legacy API key access is not allowed for workspace-scoped routes.');
    }

    public function test_stripe_webhook_event_is_processed_once(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        [$subscription] = app(WorkspaceBillingService::class)->ensureWorkspaceBilling($workspace->id);
        DB::table('workspaces')->where('id', $workspace->id)->update(['plan_id' => 'pro']);
        DB::table('billing_accounts')->where('id', $subscription->billing_account_id)->update(['plan_id' => 'pro']);
        DB::table('workspace_subscriptions')->where('id', $subscription->id)->update([
            'plan_id' => 'pro',
            'status' => 'active',
        ]);
        config(['services.stripe.webhook_secret' => 'whsec_test_secret']);

        $payload = json_encode([
            'id' => 'evt_topup_once',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_once',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_test_once',
                    'amount_total' => 1500,
                    'currency' => 'usd',
                    'customer' => 'cus_test_once',
                    'metadata' => [
                        'billing_type' => 'credit_topup',
                        'workspace_id' => $workspace->id,
                        'credit_package_id' => '11111111-1111-4111-8111-111111111111',
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $headers = [
            'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, 'whsec_test_secret'),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        $this->call('POST', '/api/billing/webhooks/stripe', [], [], [], $headers, $payload)
            ->assertOk()
            ->assertJsonPath('received', true)
            ->assertJsonPath('type', 'checkout.session.completed');

        $this->call('POST', '/api/billing/webhooks/stripe', [], [], [], $headers, $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, DB::table('credit_purchases')->where('stripe_payment_intent_id', 'pi_test_once')->count());
        $this->assertSame(1, DB::table('stripe_webhook_events')->where('stripe_event_id', 'evt_topup_once')->where('status', 'processed')->count());
    }

    public function test_canceled_subscription_cannot_spend_credits(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        $billing = app(WorkspaceBillingService::class);
        [$subscription, $wallet] = $billing->ensureWorkspaceBilling($workspace->id);

        DB::table('workspace_subscriptions')->where('id', $subscription->id)->update([
            'plan_id' => 'pro',
            'status' => 'canceled',
            'stripe_subscription_id' => 'sub_canceled_test',
        ]);
        DB::table('billing_accounts')->where('id', $subscription->billing_account_id)->update(['plan_id' => 'pro']);
        DB::table('workspace_credit_wallets')->where('id', $wallet->id)->update([
            'scrape_credits_balance' => 100,
            'ai_credits_balance' => 100,
        ]);

        $this->expectException(InsufficientCreditsException::class);

        $billing->reserveAi($workspace->id, 'test_ai_operation');
    }

    public function test_stripe_subscription_deleted_without_metadata_cancels_existing_subscription(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        $billing = app(WorkspaceBillingService::class);
        [$subscription] = $billing->ensureWorkspaceBilling($workspace->id);
        config(['services.stripe.webhook_secret' => 'whsec_test_secret']);

        DB::table('workspace_subscriptions')->where('id', $subscription->id)->update([
            'plan_id' => 'pro',
            'status' => 'active',
            'stripe_customer_id' => 'cus_cancel_without_metadata',
            'stripe_subscription_id' => 'sub_cancel_without_metadata',
        ]);
        DB::table('billing_accounts')->where('id', $subscription->billing_account_id)->update(['plan_id' => 'pro']);
        DB::table('workspaces')->where('billing_account_id', $subscription->billing_account_id)->update(['plan_id' => 'pro']);

        $payload = json_encode([
            'id' => 'evt_subscription_deleted_without_metadata',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_cancel_without_metadata',
                    'status' => 'canceled',
                    'customer' => 'cus_cancel_without_metadata',
                    'metadata' => [],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $headers = [
            'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, 'whsec_test_secret'),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        $this->call('POST', '/api/billing/webhooks/stripe', [], [], [], $headers, $payload)
            ->assertOk()
            ->assertJsonPath('received', true)
            ->assertJsonPath('type', 'customer.subscription.deleted');

        $this->assertDatabaseHas('workspace_subscriptions', [
            'id' => $subscription->id,
            'stripe_subscription_id' => 'sub_cancel_without_metadata',
            'status' => 'canceled',
        ]);
        $this->assertSame(1, DB::table('stripe_webhook_events')->where('stripe_event_id', 'evt_subscription_deleted_without_metadata')->where('status', 'processed')->count());
    }

    public function test_legacy_trial_becomes_non_expiring_evaluation_without_refilling_credits(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        $billing = app(WorkspaceBillingService::class);
        [$subscription, $wallet] = $billing->ensureWorkspaceBilling($workspace->id);

        DB::table('workspace_subscriptions')->where('id', $subscription->id)->update([
            'plan_id' => 'free',
            'status' => 'trialing',
            'trial_ends_at' => now()->subDay(),
        ]);
        DB::table('workspace_credit_wallets')->where('id', $wallet->id)->update([
            'scrape_credits_balance' => 50,
            'ai_credits_balance' => 10,
            'bonus_scrape_credits' => 5,
            'bonus_ai_credits' => 2,
        ]);

        $summary = $billing->summary($workspace->id);

        $this->assertSame('active', DB::table('workspace_subscriptions')->where('id', $subscription->id)->value('status'));
        $this->assertNull(DB::table('workspace_subscriptions')->where('id', $subscription->id)->value('trial_ends_at'));
        $this->assertSame(50, (int) DB::table('workspace_credit_wallets')->where('id', $wallet->id)->value('scrape_credits_balance'));
        $this->assertSame(10, (int) DB::table('workspace_credit_wallets')->where('id', $wallet->id)->value('ai_credits_balance'));
        $this->assertSame(5, (int) ($summary['wallet']['bonusScrapeCredits'] ?? 0));
        $this->assertSame(2, (int) ($summary['wallet']['bonusAiCredits'] ?? 0));
        $this->assertSame((string) $workspace->owner_id, $summary['billingAccount']['ownerUserId']);
        $this->assertSame((string) $workspace->id, $summary['billingAccount']['primaryWorkspaceId']);
        $this->assertSame('free', $summary['billingAccount']['planId']);
        $this->assertSame('shared_account', $summary['billingAccount']['billingScope']);
    }

    public function test_pipeline_estimate_flags_insufficient_scrape_credits(): void
    {
        [$user, $workspace] = $this->createWorkspaceForRole('owner');
        config([
            'services.apify.actors.instagram_discovery' => 'test/instagram-discovery',
            'services.apify.actors.instagram_profile' => 'test/instagram-profile',
        ]);
        $billing = app(WorkspaceBillingService::class);
        [, $wallet] = $billing->ensureWorkspaceBilling($workspace->id);

        DB::table('workspace_credit_wallets')->where('id', $wallet->id)->update([
            'scrape_credits_balance' => 0,
            'bonus_scrape_credits' => 0,
        ]);
        $this->fakeSupabaseUser($user);

        $response = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/pipeline/estimate', [
                'platform' => 'instagram',
                'hashtags' => ['skincare'],
                'discoveryLimit' => 50,
                'enrichmentLimit' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('data.billing.availableScrapeCredits', 0)
            ->assertJsonPath('data.billing.canRun', false)
            ->assertJsonPath('data.billing.requiresTopup', true);

        $this->assertGreaterThan(0, (int) $response->json('data.billing.shortfallScrapeCredits'));
    }

    public function test_stripe_topup_webhook_rejects_underpaid_session(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        app(WorkspaceBillingService::class)->ensureWorkspaceBilling($workspace->id);
        config(['services.stripe.webhook_secret' => 'whsec_test_secret']);

        $payload = json_encode([
            'id' => 'evt_underpaid_topup',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_underpaid_topup',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_underpaid_topup',
                    'amount_total' => 100,
                    'currency' => 'usd',
                    'customer' => 'cus_underpaid_topup',
                    'metadata' => [
                        'billing_type' => 'credit_topup',
                        'workspace_id' => $workspace->id,
                        'billing_account_id' => (string) DB::table('workspaces')->where('id', $workspace->id)->value('billing_account_id'),
                        'credit_package_id' => '11111111-1111-4111-8111-111111111111',
                        'expected_amount_cents' => '1900',
                        'currency' => 'usd',
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $headers = [
            'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, 'whsec_test_secret'),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        $this->call('POST', '/api/billing/webhooks/stripe', [], [], [], $headers, $payload)
            ->assertServerError();

        $this->assertSame(0, DB::table('credit_purchases')->where('stripe_payment_intent_id', 'pi_underpaid_topup')->count());
        $this->assertSame('failed', DB::table('stripe_webhook_events')->where('stripe_event_id', 'evt_underpaid_topup')->value('status'));
    }

    public function test_partial_usage_settlement_only_charges_completed_units(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        $billing = app(WorkspaceBillingService::class);
        [, $wallet] = $billing->ensureWorkspaceBilling($workspace->id);
        DB::table('workspace_credit_wallets')->where('id', $wallet->id)->update([
            'ai_credits_balance' => 100,
            'bonus_ai_credits' => 0,
            'lifetime_ai_used' => 0,
        ]);

        $reservation = $billing->reserveAi($workspace->id, 'partial-test', [
            'units' => 10,
            'credit_cost' => 10,
        ]);
        $billing->settleReservationUnits((string) $reservation['usage_event_id'], 4, 0.01);

        $event = DB::table('workspace_usage_events')->where('id', $reservation['usage_event_id'])->first();
        $freshWallet = DB::table('workspace_credit_wallets')->where('id', $wallet->id)->first();

        $this->assertSame('consumed', $event->status);
        $this->assertSame(4, (int) $event->units);
        $this->assertSame(4, (int) $event->credit_cost);
        $this->assertSame(96, (int) $freshWallet->ai_credits_balance);
        $this->assertSame(4, (int) $freshWallet->lifetime_ai_used);
    }

    public function test_zero_completed_units_refunds_the_full_reservation(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        $billing = app(WorkspaceBillingService::class);
        [, $wallet] = $billing->ensureWorkspaceBilling($workspace->id);
        DB::table('workspace_credit_wallets')->where('id', $wallet->id)->update([
            'ai_credits_balance' => 100,
            'bonus_ai_credits' => 0,
        ]);

        $reservation = $billing->reserveAi($workspace->id, 'zero-unit-test', [
            'units' => 10,
            'credit_cost' => 10,
        ]);
        $billing->settleReservationUnits((string) $reservation['usage_event_id'], 0);

        $this->assertSame('refunded', DB::table('workspace_usage_events')->where('id', $reservation['usage_event_id'])->value('status'));
        $this->assertSame(100, (int) DB::table('workspace_credit_wallets')->where('id', $wallet->id)->value('ai_credits_balance'));
    }

    public function test_duplicate_pipeline_delivery_can_only_claim_execution_once(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        $run = $this->createDiscoveryRun($workspace);
        $pipeline = app(PipelineDiscoveryService::class);

        $this->assertTrue($pipeline->claimJobExecution($run->id, 'worker-a'));
        $this->assertFalse($pipeline->claimJobExecution($run->id, 'worker-b'));

        $payload = DiscoveryRun::query()->findOrFail($run->id)->result_payload;
        $this->assertSame('worker-a', $payload['executionWorkerJobId']);
        $this->assertNotEmpty($payload['executionClaimedAt']);
    }

    public function test_workspace_can_only_have_one_active_discovery(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        $activeRun = $this->createDiscoveryRun($workspace);

        $this->expectException(ActiveDiscoveryException::class);
        app(PipelineDiscoveryService::class)->createJob([
            'workspaceId' => $workspace->id,
            'sheetId' => $activeRun->project->workbook_id,
            'platform' => 'instagram',
            'hashtags' => ['skincare'],
        ]);
    }

    public function test_cancellation_is_terminal_for_the_ui_and_persists_worker_abort_signal(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        $run = $this->createDiscoveryRun($workspace);

        $state = app(PipelineDiscoveryService::class)->requestCancellation($run->id);

        $this->assertSame('cancelled', $state['status']);
        $this->assertTrue($state['cancellationRequested']);
        $this->assertDatabaseHas('discovery_runs', ['id' => $run->id, 'status' => 'cancelled']);
    }

    public function test_stale_running_pipeline_is_marked_failed(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        $run = $this->createDiscoveryRun($workspace);
        DB::table('discovery_runs')->where('id', $run->id)->update(['updated_at' => now()->subMinutes(25)]);
        $pipeline = app(PipelineDiscoveryService::class);

        $state = $pipeline->reconcileStaleJob($run->id);

        $this->assertSame('failed', $state['status']);
        $this->assertStringContainsString('stopped responding', $state['error']);
    }

    public function test_malformed_ai_structured_output_refunds_reserved_credits(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        $billing = app(WorkspaceBillingService::class);
        [, $wallet] = $billing->ensureWorkspaceBilling($workspace->id);
        DB::table('workspace_credit_wallets')->where('id', $wallet->id)->update(['ai_credits_balance' => 100]);
        config(['services.ai.openai_key' => 'test-key', 'services.ai.openai_model' => 'test-model']);
        request()->attributes->set('workspace_id', $workspace->id);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-invalid',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
                'choices' => [['message' => ['tool_calls' => []]]],
            ], 200),
        ]);

        try {
            app(AiGatewayService::class)->structured('system', 'user', 'test_tool', 'test', ['type' => 'object']);
            $this->fail('Expected malformed structured output to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('structured payload', $exception->getMessage());
        }

        $this->assertSame('refunded', DB::table('workspace_usage_events')->latest('created_at')->value('status'));
        $this->assertSame(100, (int) DB::table('workspace_credit_wallets')->where('id', $wallet->id)->value('ai_credits_balance'));
    }

    public function test_public_readiness_does_not_expose_raw_database_exception_messages(): void
    {
        $this->getJson('/api/health/ready')
            ->assertJsonMissing(['message' => 'SQLSTATE']);
    }

    public function test_raw_scraper_routes_are_absent_when_feature_is_disabled(): void
    {
        config(['outreach.launch.enable_raw_scraper' => false]);
        $this->postJson('/api/apify/run')->assertNotFound();
    }

    public function test_tiktok_pipeline_can_be_disabled_with_a_production_feature_flag(): void
    {
        [$user, $workspace] = $this->createWorkspaceForRole('owner');
        $this->fakeSupabaseUser($user);
        config(['outreach.launch.enable_tiktok' => false]);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/pipeline/estimate', ['platform' => 'tiktok'])
            ->assertUnprocessable();
    }

    public function test_workspace_bootstrap_returns_existing_workspace_and_account_members(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner', maxMembers: 3);
        $member = User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => 'Workspace Teammate',
            'email' => 'workspace-teammate@example.test',
            'password' => 'password',
        ]);
        WorkspaceMember::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $member->supabase_user_id,
            'role' => 'member',
            'joined_at' => now(),
        ]);
        $this->fakeSupabaseUser($owner);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/workspaces/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.workspace.id', $workspace->id)
            ->assertJsonFragment([
                'user_id' => $member->supabase_user_id,
                'email' => $member->email,
                'name' => $member->name,
                'role' => 'member',
            ]);
    }

    public function test_unverified_email_is_rejected_when_verification_is_required(): void
    {
        [$user] = $this->createWorkspaceForRole('owner');
        config(['outreach.launch.require_verified_email' => true]);
        config([
            'services.supabase.url' => 'https://supabase.example.test',
            'services.supabase.service_role_key' => 'service-role-key',
        ]);
        Http::fake([
            'supabase.example.test/auth/v1/user' => Http::response([
                'id' => $user->supabase_user_id,
                'email' => $user->email,
                'user_metadata' => ['full_name' => $user->name],
            ], 200),
        ]);

        $this->withToken('valid-token')
            ->getJson('/api/workspaces/bootstrap')
            ->assertForbidden()
            ->assertJsonPath('error', 'email_verification_required');
    }

    public function test_invite_only_mode_rejects_unapproved_user(): void
    {
        $user = User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => 'Uninvited User',
            'email' => 'uninvited@example.test',
            'password' => 'password',
        ]);
        config([
            'outreach.launch.invite_only' => true,
            'outreach.launch.allowed_emails' => [],
            'outreach.launch.allowed_domains' => [],
        ]);
        $this->fakeSupabaseUser($user);

        $this->withToken('valid-token')
            ->getJson('/api/workspaces/bootstrap')
            ->assertForbidden()
            ->assertJsonPath('error', 'pilot_invitation_required');
    }

    public function test_recent_scheduler_and_worker_heartbeats_are_ready(): void
    {
        foreach (['scheduler', 'queue-worker'] as $name) {
            DB::table('operational_heartbeats')->insert([
                'name' => $name,
                'last_seen_at' => now(),
                'metadata' => json_encode(['test' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson('/api/health/ready')
            ->assertJsonPath('checks.processes.status', 'ok')
            ->assertJsonPath('checks.processes.staleProcesses', []);
    }

    private function createWorkspaceForRole(string $role, int $maxMembers = 1): array
    {
        DB::table('plans')->updateOrInsert(
            ['id' => 'free'],
            [
                'name' => 'Free',
                'max_members' => $maxMembers,
                'max_creators' => 100,
                'features' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

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

    private function createDiscoveryRun(Workspace $workspace): DiscoveryRun
    {
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Pipeline Test',
            'workbook_id' => 'workspace:'.Str::uuid(),
            'status' => 'active',
        ]);

        return DiscoveryRun::query()->create([
            'id' => (string) Str::uuid(),
            'project_id' => $project->id,
            'platform' => 'instagram',
            'provider' => 'apify',
            'status' => 'running',
            'current_step' => 'discovery_scrape',
            'request_payload' => ['workspaceId' => $workspace->id, 'platform' => 'instagram'],
            'result_payload' => [],
            'started_at' => now(),
        ]);
    }

    private function fakeSupabaseUser(User $user): void
    {
        $this->fakeSupabaseIdentity($user->supabase_user_id, $user->email, $user->name);
    }

    private function fakeSupabaseIdentity(string $userId, string $email, string $name = 'Test User'): void
    {
        config([
            'services.supabase.url' => 'https://supabase.example.test',
            'services.supabase.service_role_key' => 'service-role-key',
        ]);

        Http::fake([
            'supabase.example.test/auth/v1/user' => Http::response([
                'id' => $userId,
                'email' => $email,
                'email_confirmed_at' => now()->toIso8601String(),
                'user_metadata' => ['full_name' => $name],
            ], 200),
        ]);
    }

    private function stripeSignature(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return sprintf('t=%s,v1=%s', $timestamp, $signature);
    }
}
