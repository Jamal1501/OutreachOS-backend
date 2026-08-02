<?php

namespace Tests\Feature;

use App\Exceptions\ProviderSpendLimitException;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProviderSpendGuardService;
use App\Services\WorkspaceBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderSpendGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_daily_limit_blocks_provider_work_before_credits_are_charged(): void
    {
        $workspace = $this->workspaceFixture();
        config([
            'outreach.provider_spend.enabled' => true,
            'outreach.provider_spend.global_daily_limit_usd' => 10,
            'outreach.provider_spend.workspace_daily_limit_usd' => 0.50,
        ]);

        $billing = app(WorkspaceBillingService::class);
        $billing->reserveApify($workspace->id, 'instagram.discovery.basic', null, null, ['resultsLimit' => 10], 0.40);
        $walletBeforeBlock = DB::table('workspace_credit_wallets')->where('billing_account_id', $workspace->fresh()->billing_account_id)->first();

        try {
            $billing->reserveApify($workspace->id, 'instagram.discovery.basic', null, null, ['resultsLimit' => 10], 0.40);
            $this->fail('Expected the workspace provider-spend limit to block the second reservation.');
        } catch (ProviderSpendLimitException $exception) {
            $this->assertSame('workspace', $exception->limitContext()['blocked_scope']);
        }

        $walletAfterBlock = DB::table('workspace_credit_wallets')->where('billing_account_id', $workspace->fresh()->billing_account_id)->first();
        $this->assertSame($walletBeforeBlock->scrape_credits_balance, $walletAfterBlock->scrape_credits_balance);
        $this->assertDatabaseHas('provider_spend_blocks', [
            'workspace_id' => $workspace->id,
            'blocked_scope' => 'workspace',
            'reason_code' => 'provider_daily_spend_limit_reached',
        ]);
    }

    public function test_temporary_operator_bypass_allows_additional_workspace_spend(): void
    {
        $workspace = $this->workspaceFixture();
        config([
            'outreach.provider_spend.enabled' => true,
            'outreach.provider_spend.global_daily_limit_usd' => 10,
            'outreach.provider_spend.workspace_daily_limit_usd' => 0.50,
        ]);

        $billing = app(WorkspaceBillingService::class);
        $billing->reserveApify($workspace->id, 'instagram.discovery.basic', null, null, ['resultsLimit' => 10], 0.40);

        app(ProviderSpendGuardService::class)->updateControl(
            provider: 'apify',
            scope: 'workspace',
            workspaceId: $workspace->id,
            dailyLimitUsd: null,
            overrideLimitUsd: null,
            overrideUntil: CarbonImmutable::now()->addHour(),
            overrideReason: 'Approved pilot test',
            updatedByUserId: 'operator-user',
        );

        $reservation = $billing->reserveApify($workspace->id, 'instagram.discovery.basic', null, null, ['resultsLimit' => 10], 0.40);

        $this->assertNotEmpty($reservation['usage_event_id']);
        $overview = app(ProviderSpendGuardService::class)->overview('apify');
        $workspaceControl = collect($overview['workspaces'])->firstWhere('workspaceId', $workspace->id);
        $this->assertTrue($workspaceControl['isTemporarilyBypassed']);
        $this->assertSame(0.8, $workspaceControl['spentAndReservedTodayUsd']);
        $this->assertDatabaseHas('provider_spend_control_audits', [
            'provider' => 'apify',
            'scope_key' => 'workspace:'.$workspace->id,
            'workspace_id' => $workspace->id,
            'updated_by_user_id' => 'operator-user',
        ]);
    }

    public function test_global_limit_applies_across_workspaces(): void
    {
        $firstWorkspace = $this->workspaceFixture();
        $secondWorkspace = $this->workspaceFixture($firstWorkspace->owner_id);
        config([
            'outreach.provider_spend.enabled' => true,
            'outreach.provider_spend.global_daily_limit_usd' => 1.00,
            'outreach.provider_spend.workspace_daily_limit_usd' => 10,
        ]);

        $billing = app(WorkspaceBillingService::class);
        $billing->reserveApify($firstWorkspace->id, 'instagram.discovery.basic', null, null, ['resultsLimit' => 10], 0.60);

        $this->expectException(ProviderSpendLimitException::class);
        try {
            $billing->reserveApify($secondWorkspace->id, 'instagram.discovery.basic', null, null, ['resultsLimit' => 10], 0.60);
        } finally {
            $this->assertDatabaseHas('provider_spend_blocks', [
                'workspace_id' => $secondWorkspace->id,
                'blocked_scope' => 'global',
            ]);
        }
    }

    public function test_failure_before_provider_start_releases_reserved_spend_capacity(): void
    {
        $workspace = $this->workspaceFixture();
        config([
            'outreach.provider_spend.enabled' => true,
            'outreach.provider_spend.global_daily_limit_usd' => 10,
            'outreach.provider_spend.workspace_daily_limit_usd' => 1.00,
        ]);

        $billing = app(WorkspaceBillingService::class);
        $first = $billing->reserveApify($workspace->id, 'instagram.discovery.basic', null, null, ['resultsLimit' => 10], 0.60);
        $billing->refundReservation((string) $first['usage_event_id'], 'Provider never started');
        $second = $billing->reserveApify($workspace->id, 'instagram.discovery.basic', null, null, ['resultsLimit' => 10], 0.60);

        $this->assertNotEmpty($second['usage_event_id']);
        $event = DB::table('workspace_usage_events')->where('id', $first['usage_event_id'])->first();
        $this->assertSame('refunded', $event->status);
        $this->assertSame(0.0, (float) $event->provider_cost_actual_usd);
        $this->assertSame(0.6, $this->workspaceSpend($workspace));
    }

    public function test_partial_completion_counts_only_known_actual_provider_cost(): void
    {
        $workspace = $this->workspaceFixture();
        config([
            'outreach.provider_spend.enabled' => true,
            'outreach.provider_spend.global_daily_limit_usd' => 10,
            'outreach.provider_spend.workspace_daily_limit_usd' => 1.00,
        ]);

        $billing = app(WorkspaceBillingService::class);
        $reservation = $billing->reserveApify($workspace->id, 'instagram.discovery.basic', null, null, ['resultsLimit' => 10], 0.80);
        $billing->settleReservationUnits((string) $reservation['usage_event_id'], 5, 0.25, ['run_id' => 'provider-run-partial']);

        $this->assertSame(0.25, $this->workspaceSpend($workspace));
        $this->assertDatabaseHas('workspace_usage_events', [
            'id' => $reservation['usage_event_id'],
            'status' => 'consumed',
            'provider_cost_actual_usd' => 0.25,
        ]);
    }

    public function test_cancellation_after_provider_start_counts_known_incurred_cost(): void
    {
        $workspace = $this->workspaceFixture();
        config([
            'outreach.provider_spend.enabled' => true,
            'outreach.provider_spend.global_daily_limit_usd' => 10,
            'outreach.provider_spend.workspace_daily_limit_usd' => 1.00,
        ]);

        $billing = app(WorkspaceBillingService::class);
        $reservation = $billing->reserveApify($workspace->id, 'instagram.discovery.basic', null, null, ['resultsLimit' => 10], 0.60);
        $billing->refundReservation(
            (string) $reservation['usage_event_id'],
            'Canceled after provider start',
            ['run_id' => 'provider-run-canceled'],
            0.20,
        );

        $this->assertSame(0.2, $this->workspaceSpend($workspace));
    }

    private function workspaceSpend(Workspace $workspace): float
    {
        $overview = app(ProviderSpendGuardService::class)->overview('apify');

        return (float) collect($overview['workspaces'])
            ->firstWhere('workspaceId', $workspace->id)['spentAndReservedTodayUsd'];
    }

    private function workspaceFixture(?string $ownerId = null): Workspace
    {
        if ($ownerId === null) {
            $user = User::query()->create([
                'supabase_user_id' => (string) Str::uuid(),
                'name' => 'Provider Spend User',
                'email' => Str::random(8).'@example.test',
                'password' => 'password',
            ]);
            $ownerId = $user->supabase_user_id;
        }

        $workspace = Workspace::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Spend '.Str::random(6),
            'slug' => 'spend-'.Str::lower(Str::random(8)),
            'owner_id' => $ownerId,
            'plan_id' => 'free',
            'settings' => [],
        ]);
        DB::table('workspace_members')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $ownerId,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return $workspace;
    }
}
