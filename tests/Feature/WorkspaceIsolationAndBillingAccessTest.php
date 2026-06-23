<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientCreditsException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\WorkspaceBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    private function createWorkspaceForRole(string $role): array
    {
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

    private function stripeSignature(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        return sprintf('t=%s,v1=%s', $timestamp, $signature);
    }
}
