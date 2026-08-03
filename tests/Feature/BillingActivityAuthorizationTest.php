<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\WorkspaceBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingActivityAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_only_sees_billing_activity_for_the_selected_workspace(): void
    {
        [$owner, $admin, $workspaceA, $workspaceB, $billingAccountId] = $this->sharedBillingFixture();
        $this->fakeSupabaseUser($admin);

        $activity = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson('/api/billing/activity?page=1&perPage=20')
            ->assertOk();

        $activity->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.workspace_id', $workspaceA->id)
            ->assertJsonMissing(['workspace_id' => $workspaceB->id])
            ->assertJsonMissing(['workspace_name' => $workspaceB->name]);

        DB::table('stripe_webhook_events')->insert([
            'id' => (string) Str::uuid(),
            'stripe_event_id' => 'evt_platform_private',
            'type' => 'checkout.session.completed',
            'status' => 'failed',
            'last_error' => 'Platform-only Stripe error',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $qa = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson('/api/billing/qa-checklist')
            ->assertOk();

        $this->assertSame(
            [$workspaceA->id],
            collect($qa->json('data.recentUsageEvents'))->pluck('workspace_id')->unique()->values()->all(),
        );
        $this->assertSame(
            [$workspaceA->id],
            collect($qa->json('data.summary.workspaceBreakdown'))->pluck('workspaceId')->unique()->values()->all(),
        );
        $this->assertSame([], $qa->json('data.recentStripeWebhookEvents'));

        $summary = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson('/api/billing/summary')
            ->assertOk();
        $this->assertSame(
            [$workspaceA->id],
            collect($summary->json('data.workspaceBreakdown'))->pluck('workspaceId')->unique()->values()->all(),
        );
    }

    public function test_billing_account_owner_can_export_complete_safe_activity_rows(): void
    {
        [$owner, , $workspaceA, $workspaceB] = $this->sharedBillingFixture();
        $this->fakeSupabaseUser($owner);

        $activity = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson('/api/billing/activity?page=1&perPage=20')
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$workspaceA->id, $workspaceB->id],
            collect($activity->json('data.items'))->pluck('workspace_id')->unique()->values()->all(),
        );

        $export = $this->withToken('valid-token')
            ->withHeader('X-Workspace-Id', $workspaceA->id)
            ->get('/api/billing/activity/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $rows = $this->csvRows($export->streamedContent());
        $this->assertSame(['Date', 'Workspace', 'Activity', 'Status', 'Credits', 'Credit type', 'Reference'], $rows[0]);
        $this->assertCount(3, $rows);

        $workspaceBRow = collect(array_slice($rows, 1))->first(fn (array $row) => $row[1] === "'".$workspaceB->name);
        $this->assertNotNull($workspaceBRow);
        $this->assertStringContainsString(now()->format('Y-m-d'), $workspaceBRow[0]);
        $this->assertSame('Creator enrichment: 9 workflow credits used', $workspaceBRow[2]);
        $this->assertSame('9', $workspaceBRow[4]);
        $this->assertSame("'@private-provider-reference", $workspaceBRow[6]);
    }

    private function sharedBillingFixture(): array
    {
        DB::table('plans')->updateOrInsert(
            ['id' => 'free'],
            [
                'name' => 'Free',
                'max_members' => 5,
                'max_creators' => 100,
                'features' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $owner = User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => 'Billing Owner',
            'email' => 'billing-owner@example.test',
            'password' => 'password',
        ]);
        $admin = User::query()->create([
            'supabase_user_id' => (string) Str::uuid(),
            'name' => 'Workspace A Admin',
            'email' => 'workspace-admin@example.test',
            'password' => 'password',
        ]);
        $workspaceA = $this->workspace('Workspace A', $owner->supabase_user_id);
        $workspaceB = $this->workspace('=Private Workspace B', $owner->supabase_user_id);

        $this->membership($workspaceA, $owner, 'owner');
        $this->membership($workspaceB, $owner, 'owner');
        $this->membership($workspaceA, $admin, 'admin');

        [, , , $billingAccount] = app(WorkspaceBillingService::class)->ensureWorkspaceBilling($workspaceA->id);
        app(WorkspaceBillingService::class)->ensureWorkspaceBilling($workspaceB->id);
        DB::table('workspace_subscriptions')
            ->where('billing_account_id', $billingAccount->id)
            ->update(['current_period_start' => now()->subDay()]);

        $this->usageEvent($workspaceA, (string) $billingAccount->id, 'workspace-a-reference', 4, now()->subMinutes(2)->toDateTimeString());
        $this->usageEvent($workspaceB, (string) $billingAccount->id, '@private-provider-reference', 9, now()->subMinute()->toDateTimeString());

        return [$owner, $admin, $workspaceA, $workspaceB, (string) $billingAccount->id];
    }

    private function workspace(string $name, string $ownerId): Workspace
    {
        return Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => 'billing-'.Str::lower(Str::random(10)),
            'owner_id' => $ownerId,
            'plan_id' => 'free',
            'settings' => [],
        ]);
    }

    private function membership(Workspace $workspace, User $user, string $role): void
    {
        WorkspaceMember::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $user->supabase_user_id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    private function usageEvent(Workspace $workspace, string $billingAccountId, string $reference, int $credits, string $at): void
    {
        DB::table('workspace_usage_events')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'billing_account_id' => $billingAccountId,
            'type' => 'enrichment',
            'credit_bucket' => 'scrape',
            'units' => 1,
            'credit_cost' => $credits,
            'provider' => 'apify',
            'source' => 'instagram.enrichment.deep',
            'status' => 'consumed',
            'reference_id' => $reference,
            'metadata' => json_encode([]),
            'consumed_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
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
