<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
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
                    'amount_total' => 1900,
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
