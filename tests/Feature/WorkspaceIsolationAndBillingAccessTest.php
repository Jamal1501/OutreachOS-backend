<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientCreditsException;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\WorkspaceBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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

    public function test_existing_user_keeps_workspace_when_supabase_identity_changes(): void
    {
        [$user, $workspace] = $this->createWorkspaceForRole('owner');
        $previousSupabaseUserId = (string) $user->supabase_user_id;
        $nextSupabaseUserId = (string) Str::uuid();
        $this->fakeSupabaseIdentity($nextSupabaseUserId, $user->email, $user->name);

        $this->withToken('valid-token')
            ->getJson('/api/workspaces/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.workspace.id', $workspace->id)
            ->assertJsonPath('data.membership.user_id', $nextSupabaseUserId);

        $this->assertDatabaseHas('users', [
            'email' => $user->email,
            'supabase_user_id' => $nextSupabaseUserId,
        ]);
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $nextSupabaseUserId,
            'role' => 'owner',
        ]);
        $this->assertDatabaseMissing('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $previousSupabaseUserId,
        ]);
    }

    public function test_workspace_bootstrap_restores_missing_owner_membership(): void
    {
        [$owner, $workspace] = $this->createWorkspaceForRole('owner');
        WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $owner->supabase_user_id)
            ->delete();
        $this->fakeSupabaseUser($owner);

        $this->withToken('valid-token')
            ->getJson('/api/workspaces/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.workspace.id', $workspace->id)
            ->assertJsonPath('data.membership.role', 'owner');

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $owner->supabase_user_id,
            'role' => 'owner',
        ]);
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
        config(['services.stripe.webhook_secret' => 'whsec_test_secret']);

        $payload = json_encode([
            'id' => 'evt_topup_once',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_once',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_test_once',
                    'amount_total' => 2375,
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
            'email' => Str::random(8) . '@example.test',
            'password' => 'password',
        ]);

        $workspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Workspace',
            'slug' => 'workspace-' . Str::random(8),
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
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        return sprintf('t=%s,v1=%s', $timestamp, $signature);
    }
}
