<?php

namespace Tests\Feature;

use App\Exceptions\ActiveDiscoveryException;
use App\Exceptions\InsufficientCreditsException;
use App\Mail\OperationalAlertMail;
use App\Mail\WorkspaceAccessGrantedMail;
use App\Mail\WorkspaceInvitationMail;
use App\Models\DiscoveryRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AiGatewayService;
use App\Services\PipelineDiscoveryService;
use App\Services\StripeBillingService;
use App\Services\WorkspaceBillingService;
use Illuminate\Database\QueryException;
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

    public function test_workspace_admins_cannot_start_financial_checkout(): void
    {
        [$user, $workspace] = $this->createWorkspaceForRole('admin');
        $this->fakeSupabaseUser($user);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/billing/checkout/subscription', [
                'planId' => 'pro',
                'successUrl' => 'https://www.socialcore.app/billing?checkout=success',
                'cancelUrl' => 'https://www.socialcore.app/billing?checkout=cancelled',
            ])
            ->assertForbidden();
    }

    public function test_active_stripe_subscription_cannot_open_another_subscription_checkout(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner');
        [$subscription] = app(WorkspaceBillingService::class)->ensureWorkspaceBilling($workspace->id);
        DB::table('workspace_subscriptions')->where('id', $subscription->id)->update([
            'plan_id' => 'pro',
            'status' => 'active',
            'stripe_customer_id' => 'cus_existing',
            'stripe_subscription_id' => 'sub_existing',
        ]);
        $this->fakeSupabaseUser($owner);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/billing/checkout/subscription', [
                'planId' => 'enterprise',
                'successUrl' => 'https://www.socialcore.app/billing?checkout=success',
                'cancelUrl' => 'https://www.socialcore.app/billing?checkout=cancelled',
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'active_subscription_exists');
    }

    public function test_pending_same_plan_checkout_prevents_a_second_stripe_session(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner');
        $checkoutCalls = 0;
        $this->fakeSubscriptionCheckoutProviders($owner, $checkoutCalls);

        $payload = [
            'planId' => 'pro',
            'successUrl' => 'https://www.socialcore.app/billing?checkout=success',
            'cancelUrl' => 'https://www.socialcore.app/billing?checkout=cancelled',
        ];

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/billing/checkout/subscription', $payload)
            ->assertOk();

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/billing/checkout/subscription', $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'subscription_checkout_pending');

        $this->assertSame(1, $checkoutCalls);
    }

    public function test_pending_different_plan_checkout_prevents_a_second_stripe_session(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner');
        $checkoutCalls = 0;
        $this->fakeSubscriptionCheckoutProviders($owner, $checkoutCalls);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/billing/checkout/subscription', [
                'planId' => 'pro',
                'successUrl' => 'https://www.socialcore.app/billing?checkout=success',
                'cancelUrl' => 'https://www.socialcore.app/billing?checkout=cancelled',
            ])
            ->assertOk();

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/billing/checkout/subscription', [
                'planId' => 'enterprise',
                'successUrl' => 'https://www.socialcore.app/billing?checkout=success',
                'cancelUrl' => 'https://www.socialcore.app/billing?checkout=cancelled',
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'subscription_checkout_pending');

        $this->assertSame(1, $checkoutCalls);
    }

    public function test_canceling_pending_checkout_expires_it_before_allowing_another_plan(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner');
        $checkoutCalls = 0;
        $this->fakeSubscriptionCheckoutProviders($owner, $checkoutCalls);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/billing/checkout/subscription', [
                'planId' => 'pro',
                'successUrl' => 'https://www.socialcore.app/billing?checkout=success',
                'cancelUrl' => 'https://www.socialcore.app/billing?checkout=cancelled',
            ])
            ->assertOk();

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/billing/checkout/subscription/cancel')
            ->assertOk()
            ->assertJsonPath('data.cancelled', true);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/billing/checkout/subscription', [
                'planId' => 'enterprise',
                'successUrl' => 'https://www.socialcore.app/billing?checkout=success',
                'cancelUrl' => 'https://www.socialcore.app/billing?checkout=cancelled',
            ])
            ->assertOk();

        $this->assertSame(2, $checkoutCalls);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions/cs_guarded_checkout/expire');
    }

    public function test_database_enforces_one_subscription_per_billing_account(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        [$subscription] = app(WorkspaceBillingService::class)->ensureWorkspaceBilling($workspace->id);

        $this->expectException(QueryException::class);
        DB::table('workspace_subscriptions')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) Str::uuid(),
            'billing_account_id' => $subscription->billing_account_id,
            'plan_id' => 'free',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_owner_can_open_stripe_customer_portal(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner');
        [$subscription] = app(WorkspaceBillingService::class)->ensureWorkspaceBilling($workspace->id);
        DB::table('workspace_subscriptions')->where('id', $subscription->id)->update([
            'stripe_customer_id' => 'cus_portal_test',
        ]);
        config([
            'services.stripe.secret_key' => 'sk_test_portal',
            'services.supabase.url' => 'https://supabase.example.test',
            'services.supabase.service_role_key' => 'service-role-key',
        ]);
        Http::fake([
            'supabase.example.test/auth/v1/user' => Http::response([
                'id' => $owner->supabase_user_id,
                'email' => $owner->email,
                'email_confirmed_at' => now()->toIso8601String(),
                'user_metadata' => ['full_name' => $owner->name],
            ]),
            'api.stripe.com/v1/billing_portal/sessions' => Http::response([
                'id' => 'bps_test',
                'url' => 'https://billing.stripe.com/p/session/test',
            ]),
        ]);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/billing/customer-portal', [
                'returnUrl' => 'https://www.socialcore.app/billing',
            ])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://billing.stripe.com/p/session/test');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/billing_portal/sessions'
            && $request['customer'] === 'cus_portal_test');
    }

    public function test_workspace_deletion_is_blocked_while_stripe_subscription_is_active(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner');
        [$subscription] = app(WorkspaceBillingService::class)->ensureWorkspaceBilling($workspace->id);
        DB::table('workspace_subscriptions')->where('id', $subscription->id)->update([
            'plan_id' => 'pro',
            'status' => 'active',
            'stripe_customer_id' => 'cus_delete_guard',
            'stripe_subscription_id' => 'sub_delete_guard',
        ]);
        $this->fakeSupabaseUser($owner);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->deleteJson('/api/workspaces/'.$workspace->id)
            ->assertConflict()
            ->assertJsonPath('code', 'active_subscription_must_be_canceled');

        $this->assertDatabaseMissing('data_deletion_requests', [
            'workspace_id' => $workspace->id,
            'status' => 'scheduled',
        ]);
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
        Mail::assertSent(WorkspaceInvitationMail::class, fn (WorkspaceInvitationMail $mail) => $mail->hasTo('teammate@example.test')
            && str_contains($mail->inviteUrl, 'mode=signup')
            && str_contains($mail->inviteUrl, 'workspaceId='.urlencode((string) $workspace->id)));
    }

    public function test_existing_user_is_added_to_another_workspace_and_notified(): void
    {
        Mail::fake();
        [$owner, $workspace] = $this->createWorkspaceForRole('owner', maxMembers: 3);
        $existingUser = User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => 'Existing User',
            'email' => 'existing@example.test',
            'password' => 'password',
        ]);
        $this->fakeSupabaseUser($owner);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/workspaces/invitations', [
                'email' => 'existing@example.test',
                'role' => 'member',
            ])
            ->assertCreated()
            ->assertJsonPath('data.assignedWorkspaces', 1)
            ->assertJsonPath('data.pendingInvitations', 0)
            ->assertJsonPath('data.emailDelivery.status', 'sent');

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $existingUser->supabase_user_id,
            'role' => 'member',
        ]);
        Mail::assertSent(WorkspaceAccessGrantedMail::class, fn (WorkspaceAccessGrantedMail $mail) => $mail->hasTo('existing@example.test')
            && str_contains($mail->workspaceUrl, 'mode=login')
            && str_contains($mail->workspaceUrl, 'workspaceId='.urlencode((string) $workspace->id)));
    }

    public function test_invitation_cannot_grant_owner_role(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner', maxMembers: 3);
        $this->fakeSupabaseUser($owner);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/workspaces/invitations', [
                'email' => 'should-not-be-owner@example.test',
                'role' => 'owner',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('workspace_invitations', [
            'workspace_id' => $workspace->id,
            'email' => 'should-not-be-owner@example.test',
        ]);
    }

    public function test_invitation_creation_reports_email_delivery_failure_truthfully(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner', maxMembers: 3);
        $this->fakeSupabaseUser($owner);
        config(['mail.default' => 'missing-test-mailer']);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/workspaces/invitations', [
                'email' => 'delivery-failure@example.test',
                'role' => 'member',
            ])
            ->assertCreated()
            ->assertJsonPath('data.pendingInvitations', 1)
            ->assertJsonPath('data.emailDelivery.status', 'failed')
            ->assertJsonPath('data.emailDelivery.failed', 1);

        $this->assertDatabaseHas('workspace_invitations', [
            'workspace_id' => $workspace->id,
            'email' => 'delivery-failure@example.test',
            'accepted_at' => null,
        ]);
    }

    public function test_workspace_audit_log_excludes_billing_system_events(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner');
        $this->fakeSupabaseUser($owner);

        DB::table('workspace_audit_events')->insert([
            [
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'actor_user_id' => $owner->supabase_user_id,
                'event_type' => 'billing_credits_reserved',
                'subject_type' => 'billing',
                'subject_id' => (string) Str::uuid(),
                'metadata' => json_encode(['credit_cost' => 12]),
                'created_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'actor_user_id' => $owner->supabase_user_id,
                'event_type' => 'member_assigned',
                'subject_type' => 'user',
                'subject_id' => (string) Str::uuid(),
                'metadata' => json_encode(['email' => 'member@example.test']),
                'created_at' => now()->addSecond(),
            ],
        ]);

        $response = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/workspaces/audit?limit=40')
            ->assertOk();

        $this->assertSame(['member_assigned'], collect($response->json('data.events'))->pluck('event_type')->all());
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

    public function test_different_active_stripe_subscription_cannot_overwrite_local_billing(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        [$subscription] = app(WorkspaceBillingService::class)->ensureWorkspaceBilling($workspace->id);
        DB::table('workspace_subscriptions')->where('id', $subscription->id)->update([
            'plan_id' => 'pro',
            'status' => 'active',
            'stripe_customer_id' => 'cus_conflict',
            'stripe_subscription_id' => 'sub_existing',
        ]);
        config([
            'services.stripe.webhook_secret' => 'whsec_test_secret',
            'observability.alerts.enabled' => true,
            'observability.alerts.email' => 'operator@example.test',
        ]);
        Mail::fake();

        $payload = json_encode([
            'id' => 'evt_subscription_conflict',
            'type' => 'customer.subscription.created',
            'data' => ['object' => [
                'id' => 'sub_duplicate',
                'status' => 'active',
                'customer' => 'cus_conflict',
                'metadata' => [
                    'workspace_id' => $workspace->id,
                    'billing_account_id' => $subscription->billing_account_id,
                    'plan_id' => 'enterprise',
                ],
            ]],
        ], JSON_UNESCAPED_SLASHES);

        $this->call('POST', '/api/billing/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, 'whsec_test_secret'),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertServerError();

        $this->assertDatabaseHas('workspace_subscriptions', [
            'id' => $subscription->id,
            'plan_id' => 'pro',
            'stripe_subscription_id' => 'sub_existing',
        ]);
        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_subscription_conflict',
            'status' => 'failed',
            'last_error' => 'stripe_subscription_conflict',
        ]);
        Mail::assertSent(OperationalAlertMail::class);
    }

    public function test_stale_processing_stripe_webhook_is_reclaimed_and_processed(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        [$subscription] = app(WorkspaceBillingService::class)->ensureWorkspaceBilling($workspace->id);
        DB::table('workspaces')->where('id', $workspace->id)->update(['plan_id' => 'pro']);
        DB::table('billing_accounts')->where('id', $subscription->billing_account_id)->update(['plan_id' => 'pro']);
        DB::table('workspace_subscriptions')->where('id', $subscription->id)->update(['plan_id' => 'pro', 'status' => 'active']);
        config([
            'services.stripe.webhook_secret' => 'whsec_test_secret',
            'outreach.billing.stripe_webhook_processing_lease_minutes' => 10,
        ]);
        DB::table('stripe_webhook_events')->insert([
            'id' => (string) Str::uuid(),
            'stripe_event_id' => 'evt_stale_topup',
            'type' => 'checkout.session.completed',
            'status' => 'processing',
            'attempt_count' => 1,
            'last_attempt_at' => now()->subMinutes(11),
            'created_at' => now()->subMinutes(11),
            'updated_at' => now()->subMinutes(11),
        ]);

        $payload = json_encode([
            'id' => 'evt_stale_topup',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_stale_topup',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_stale_topup',
                    'amount_total' => 1500,
                    'currency' => 'usd',
                    'customer' => 'cus_stale_topup',
                    'metadata' => [
                        'billing_type' => 'credit_topup',
                        'workspace_id' => $workspace->id,
                        'credit_package_id' => '11111111-1111-4111-8111-111111111111',
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $this->call('POST', '/api/billing/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, 'whsec_test_secret'),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)
            ->assertOk()
            ->assertJsonPath('received', true);

        $event = DB::table('stripe_webhook_events')->where('stripe_event_id', 'evt_stale_topup')->first();
        $this->assertSame('processed', $event->status);
        $this->assertSame(2, (int) $event->attempt_count);
        $this->assertSame(1, DB::table('credit_purchases')->where('stripe_payment_intent_id', 'pi_stale_topup')->count());
    }

    public function test_billing_reconciliation_recovers_a_failed_topup_webhook(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        [$subscription] = app(WorkspaceBillingService::class)->ensureWorkspaceBilling($workspace->id);
        DB::table('workspaces')->where('id', $workspace->id)->update(['plan_id' => 'pro']);
        DB::table('billing_accounts')->where('id', $subscription->billing_account_id)->update(['plan_id' => 'pro']);
        DB::table('workspace_subscriptions')->where('id', $subscription->id)->update(['plan_id' => 'pro', 'status' => 'active']);
        DB::table('stripe_webhook_events')->insert([
            'id' => (string) Str::uuid(),
            'stripe_event_id' => 'evt_reconcile_topup',
            'type' => 'checkout.session.completed',
            'status' => 'failed',
            'attempt_count' => 1,
            'last_attempt_at' => now()->subMinute(),
            'last_error' => 'Temporary test failure',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        config(['services.stripe.secret_key' => 'sk_test_reconcile']);
        Http::fake([
            'api.stripe.com/v1/events/evt_reconcile_topup' => Http::response([
                'id' => 'evt_reconcile_topup',
                'type' => 'checkout.session.completed',
                'data' => ['object' => [
                    'id' => 'cs_reconcile_topup',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_reconcile_topup',
                    'amount_total' => 1500,
                    'currency' => 'usd',
                    'customer' => 'cus_reconcile_topup',
                    'metadata' => [
                        'billing_type' => 'credit_topup',
                        'workspace_id' => $workspace->id,
                        'credit_package_id' => '11111111-1111-4111-8111-111111111111',
                    ],
                ]],
            ]),
        ]);

        $result = app(StripeBillingService::class)->reconcileRecoverableWebhookEvents();

        $this->assertSame(['checked' => 1, 'recovered' => 1, 'errors' => 0], $result);
        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_reconcile_topup',
            'status' => 'processed',
            'attempt_count' => 2,
        ]);
        $this->assertDatabaseHas('credit_purchases', ['stripe_payment_intent_id' => 'pi_reconcile_topup']);
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
        $this->assertSame(90, (int) $reservation['remaining_balance']);
        $this->assertSame(90, (int) $reservation['remaining_base_balance']);
        $this->assertSame(0, (int) $reservation['remaining_bonus_balance']);
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
        $this->assertMatchesRegularExpression('/^ERR-[A-Z0-9]{10}$/', (string) $state['errorReference']);
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

    public function test_successful_ai_generation_exposes_exact_credit_usage_for_the_response(): void
    {
        [, $workspace] = $this->createWorkspaceForRole('owner');
        $billing = app(WorkspaceBillingService::class);
        [, $wallet] = $billing->ensureWorkspaceBilling($workspace->id);
        DB::table('workspace_credit_wallets')->where('id', $wallet->id)->update([
            'ai_credits_balance' => 7,
            'bonus_ai_credits' => 2,
            'lifetime_ai_used' => 0,
        ]);
        config(['services.ai.openai_key' => 'test-key', 'services.ai.openai_model' => 'test-model']);
        request()->attributes->set('workspace_id', $workspace->id);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-valid',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
                'choices' => [['message' => ['tool_calls' => [[
                    'function' => ['arguments' => json_encode(['value' => 'ok'])],
                ]]]]],
            ], 200),
        ]);

        $result = app(AiGatewayService::class)->structured(
            'system',
            'user',
            'test_tool',
            'test',
            [
                'type' => 'object',
                'properties' => ['value' => ['type' => 'string']],
                'required' => ['value'],
                'additionalProperties' => false,
            ],
        );

        $usage = (array) request()->attributes->get('ai_credit_usage');
        $this->assertSame('ok', $result['value']);
        $this->assertSame(1, (int) $usage['credits_consumed']);
        $this->assertSame(8, (int) $usage['remaining_balance']);
        $this->assertSame(6, (int) $usage['remaining_base_balance']);
        $this->assertSame(2, (int) $usage['remaining_bonus_balance']);
        $this->assertCount(1, $usage['usage_event_ids']);
    }

    public function test_personalized_message_response_includes_the_live_ai_credit_balance(): void
    {
        [$user, $workspace] = $this->createWorkspaceForRole('owner');
        $this->fakeSupabaseUser($user);
        $billing = app(WorkspaceBillingService::class);
        [, $wallet] = $billing->ensureWorkspaceBilling($workspace->id);
        DB::table('workspace_credit_wallets')->where('id', $wallet->id)->update([
            'ai_credits_balance' => 7,
            'bonus_ai_credits' => 2,
            'lifetime_ai_used' => 0,
        ]);
        config(['services.ai.openai_key' => 'test-key', 'services.ai.openai_model' => 'test-model']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-personalized',
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 15, 'total_tokens' => 35],
                'choices' => [
                    [
                        'message' => [
                            'tool_calls' => [
                                [
                                    'function' => [
                                        'arguments' => json_encode([
                                            'personalizedMessage' => 'Hi Alex, your practical creator tutorials match a paid campaign we are preparing. Open to a short overview?',
                                            'emailSubject' => '',
                                            'personalizationNotes' => 'Used the supplied creator context.',
                                            'creativeAngle' => 'Practical tutorial',
                                            'contentIdea' => 'Short product walkthrough',
                                            'fitScore' => 82,
                                            'confidenceScore' => 0.82,
                                            'toneUsed' => 'casual',
                                            'messageType' => 'dm',
                                            'analysis' => [
                                                'creatorSummary' => 'Practical tutorial creator',
                                                'styleSignals' => ['clear'],
                                                'audienceSignals' => ['tutorial viewers'],
                                                'proofPoints' => ['supplied bio'],
                                                'personalizationHooks' => ['practical tutorials'],
                                                'risksToAvoid' => [],
                                                'recommendedAngle' => 'Practical tutorial',
                                                'creatorContentIdea' => 'Short product walkthrough',
                                                'contentMechanic' => 'tutorial',
                                                'selectedEvidenceHook' => 'practical tutorials',
                                                'evidenceQuality' => 'medium',
                                                'fallbackReason' => '',
                                                'toneUsed' => 'casual',
                                                'fitScore' => 82,
                                            ],
                                        ]),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/ai/personalize-message', [
                'creator' => [
                    'handle' => '@alex',
                    'platform' => 'instagram',
                    'bio' => 'Practical creator tutorials',
                ],
                'messageType' => 'dm',
                'stage' => 'cold_invite',
            ]);

        $response->assertOk()
            ->assertJsonPath('_billing.aiCreditsConsumed', 1)
            ->assertJsonPath('_billing.aiCreditsRemaining', 8)
            ->assertJsonPath('_billing.aiCreditsBalance', 6)
            ->assertJsonPath('_billing.bonusAiCredits', 2);
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

    public function test_archived_workspace_is_inaccessible_until_it_is_restored(): void
    {
        [$owner, $primaryWorkspace] = $this->createWorkspaceForRole('owner');
        [, , , $billingAccount] = app(WorkspaceBillingService::class)->ensureWorkspaceBilling($primaryWorkspace->id);
        $secondaryWorkspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Archived Client',
            'slug' => 'archived-client-'.Str::lower(Str::random(8)),
            'owner_id' => $owner->supabase_user_id,
            'billing_account_id' => $billingAccount->id,
            'plan_id' => 'free',
            'settings' => ['workspaceDataKey' => 'workspace:archived-client'],
        ]);
        WorkspaceMember::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $secondaryWorkspace->id,
            'user_id' => $owner->supabase_user_id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $this->fakeSupabaseUser($owner);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $secondaryWorkspace->id)
            ->getJson('/api/auth-check')
            ->assertOk();

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $primaryWorkspace->id)
            ->postJson('/api/workspaces/'.$secondaryWorkspace->id.'/archive')
            ->assertOk();

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $secondaryWorkspace->id)
            ->getJson('/api/auth-check')
            ->assertStatus(423)
            ->assertJsonPath('error', 'workspace_archived');

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $secondaryWorkspace->id)
            ->getJson('/api/workspaces/bootstrap')
            ->assertStatus(423)
            ->assertJsonPath('error', 'workspace_archived');

        $this->withToken('valid-token')
            ->postJson('/api/workspaces/'.$secondaryWorkspace->id.'/restore')
            ->assertOk()
            ->assertJsonPath('data.workspace.id', $secondaryWorkspace->id);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $secondaryWorkspace->id)
            ->getJson('/api/auth-check')
            ->assertOk();
    }

    public function test_last_active_workspace_cannot_be_archived(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner');
        $this->fakeSupabaseUser($owner);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/workspaces/'.$workspace->id.'/archive')
            ->assertConflict()
            ->assertJsonPath('error', 'last_active_workspace');

        $this->assertFalse((bool) data_get($workspace->fresh()->settings, 'archivedAt'));
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

        config(['observability.health.details_token' => 'test-operational-details-token']);

        $this->withToken('test-operational-details-token')
            ->getJson('/api/health/operational/details')
            ->assertJsonPath('checks.processes.status', 'ok')
            ->assertJsonPath('checks.processes.staleProcesses', []);
    }

    public function test_billing_activity_has_customer_readable_amounts_workspace_and_reference(): void
    {
        [$user, $workspace] = $this->createWorkspaceForRole('owner');
        $this->fakeSupabaseUser($user);
        [, , , $billingAccount] = app(WorkspaceBillingService::class)->ensureWorkspaceBilling($workspace->id);
        $reference = 'provider-run-123';
        DB::table('workspace_usage_events')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'billing_account_id' => $billingAccount->id,
            'type' => 'enrichment',
            'credit_bucket' => 'scrape',
            'units' => 2,
            'credit_cost' => 7,
            'provider' => 'apify',
            'source' => 'instagram.enrichment.deep',
            'status' => 'consumed',
            'reference_id' => $reference,
            'metadata' => json_encode([]),
            'consumed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (range(1, 20) as $index) {
            DB::table('workspace_usage_events')->insert([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'billing_account_id' => $billingAccount->id,
                'type' => 'ai_message',
                'credit_bucket' => 'ai',
                'units' => 1,
                'credit_cost' => 1,
                'provider' => 'openai',
                'source' => 'ai.draft',
                'status' => 'consumed',
                'metadata' => json_encode([]),
                'consumed_at' => now()->subMinutes($index),
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/billing/activity?page=1&perPage=20')
            ->assertOk()
            ->assertJsonPath('data.items.0.description', 'Creator enrichment: 7 workflow credits used')
            ->assertJsonPath('data.items.0.workspace_name', $workspace->name)
            ->assertJsonPath('data.items.0.reference_id', $reference)
            ->assertJsonPath('data.pagination.perPage', 20)
            ->assertJsonPath('data.pagination.total', 21)
            ->assertJsonPath('data.pagination.lastPage', 2)
            ->assertJsonCount(20, 'data.items');

        $export = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->get('/api/billing/activity/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Creator enrichment: 7 workflow credits used', $export->streamedContent());
    }

    public function test_workspace_export_is_recorded_in_the_audit_log(): void
    {
        [$user, $workspace] = $this->createWorkspaceForRole('owner');
        $this->fakeSupabaseUser($user);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->get('/api/workspaces/'.$workspace->id.'/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/json; charset=UTF-8');

        $this->assertDatabaseHas('workspace_audit_events', [
            'workspace_id' => $workspace->id,
            'actor_user_id' => $user->supabase_user_id,
            'event_type' => 'workspace_export_requested',
            'subject_type' => 'workspace',
            'subject_id' => $workspace->id,
        ]);
    }

    public function test_account_owner_must_handle_owned_workspaces_before_account_deletion(): void
    {
        [$user, $workspace] = $this->createWorkspaceForRole('owner');
        $this->fakeSupabaseUser($user);

        $this->withToken('valid-token')
            ->deleteJson('/api/account')
            ->assertStatus(409)
            ->assertJsonPath('code', 'owned_workspaces_require_action')
            ->assertJsonPath('workspaces.0.id', $workspace->id);

        $this->assertDatabaseMissing('data_deletion_requests', [
            'type' => 'account',
            'user_id' => $user->supabase_user_id,
            'status' => 'scheduled',
        ]);
    }

    public function test_workspace_creation_is_idempotent_when_the_browser_retries(): void
    {
        DB::table('plans')->updateOrInsert(
            ['id' => 'free'],
            ['name' => 'Free', 'max_members' => 3, 'max_creators' => 100, 'features' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]
        );
        $user = User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => 'New owner',
            'email' => 'new-owner@example.test',
            'password' => 'password',
        ]);
        $this->fakeSupabaseUser($user);
        $creationRequestId = (string) Str::uuid();
        $payload = [
            'name' => 'Idempotent workspace',
            'creationRequestId' => $creationRequestId,
            'onboarding' => ['version' => 2, 'primaryGoal' => 'discover', 'teamSize' => 1],
        ];

        $firstId = $this->withToken('valid-token')->postJson('/api/workspaces', $payload)
            ->assertCreated()
            ->json('data.workspace.id');
        $secondId = $this->withToken('valid-token')->postJson('/api/workspaces', $payload)
            ->assertOk()
            ->json('data.workspace.id');

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, Workspace::query()->where('creation_request_id', $creationRequestId)->count());
    }

    public function test_different_owners_can_reuse_the_same_workspace_creation_request_id(): void
    {
        DB::table('plans')->updateOrInsert(
            ['id' => 'free'],
            ['name' => 'Free', 'max_members' => 3, 'max_creators' => 100, 'features' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]
        );
        $users = collect(['first', 'second'])->map(fn (string $label) => User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => Str::headline($label).' Owner',
            'email' => $label.'-owner@example.test',
            'password' => 'password',
        ]));
        $creationRequestId = (string) Str::uuid();

        foreach ($users as $index => $user) {
            Workspace::query()->create([
                'id' => (string) Str::uuid(),
                'name' => 'Workspace '.($index + 1),
                'slug' => 'request-scope-'.Str::lower(Str::random(10)),
                'owner_id' => $user->supabase_user_id,
                'plan_id' => 'free',
                'creation_request_id' => $creationRequestId,
                'settings' => [],
            ]);
        }

        $this->assertSame(2, Workspace::query()->where('creation_request_id', $creationRequestId)->count());
        $this->assertSame(2, Workspace::query()->whereIn('owner_id', $users->pluck('supabase_user_id'))->count());
    }

    public function test_personal_onboarding_progress_is_saved_per_workspace_member(): void
    {
        [$user, $workspace] = $this->createWorkspaceForRole('member');
        $this->fakeSupabaseUser($user);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->putJson('/api/workspaces/onboarding/personal', [
                'dismissedRoutes' => ['/dashboard', '/not-a-real-route'],
                'hidden' => true,
                'lastRoute' => '/crm',
            ])
            ->assertOk()
            ->assertJsonPath('data.dismissedRoutes', ['/dashboard'])
            ->assertJsonPath('data.lastRoute', '/crm');

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/workspaces/onboarding/personal')
            ->assertOk()
            ->assertJsonPath('data.dismissedRoutes', ['/dashboard'])
            ->assertJsonPath('data.lastRoute', '/crm')
            ->assertJsonPath('data.version', 2);

        $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->putJson('/api/workspaces/onboarding/personal', [
                'lastRoute' => '/tasks',
            ])
            ->assertOk()
            ->assertJsonPath('data.dismissedRoutes', ['/dashboard'])
            ->assertJsonPath('data.lastRoute', '/tasks');

        $this->assertSame(1, DB::table('workspace_user_onboarding_states')
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->supabase_user_id)
            ->count());
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

    private function fakeSubscriptionCheckoutProviders(User $owner, int &$checkoutCalls): void
    {
        config([
            'services.stripe.secret_key' => 'sk_test_checkout',
            'services.supabase.url' => 'https://supabase.example.test',
            'services.supabase.service_role_key' => 'service-role-key',
        ]);

        Http::fake(function ($request) use ($owner, &$checkoutCalls) {
            if (str_contains($request->url(), 'supabase.example.test/auth/v1/user')) {
                return Http::response([
                    'id' => $owner->supabase_user_id,
                    'email' => $owner->email,
                    'email_confirmed_at' => now()->toIso8601String(),
                    'user_metadata' => ['full_name' => $owner->name],
                ]);
            }
            if ($request->url() === 'https://api.stripe.com/v1/customers') {
                return Http::response(['id' => 'cus_checkout_guard']);
            }
            if ($request->url() === 'https://api.stripe.com/v1/checkout/sessions') {
                $checkoutCalls++;
                $subscription = DB::table('workspace_subscriptions')->first();
                $metadata = json_decode((string) $subscription->metadata, true);
                $pending = (array) ($metadata['pending_subscription_checkout'] ?? []);
                $this->assertSame('creating', $pending['status'] ?? null);
                $this->assertNotEmpty($pending['intent_token'] ?? null);
                $this->assertSame(
                    'subscription-checkout-'.hash('sha256', (string) $pending['intent_token']),
                    $request->header('Idempotency-Key')[0] ?? null,
                );

                return Http::response([
                    'id' => 'cs_guarded_checkout',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_guarded_checkout',
                    'expires_at' => now()->addHours(23)->timestamp,
                ]);
            }
            if ($request->url() === 'https://api.stripe.com/v1/checkout/sessions/cs_guarded_checkout/expire') {
                return Http::response(['id' => 'cs_guarded_checkout', 'status' => 'expired']);
            }

            return Http::response(['error' => 'unexpected_test_request'], 500);
        });
    }

    private function stripeSignature(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return sprintf('t=%s,v1=%s', $timestamp, $signature);
    }
}
