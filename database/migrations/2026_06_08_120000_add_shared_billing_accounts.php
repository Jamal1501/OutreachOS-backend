<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->createBillingAccounts();
        $this->addBillingAccountColumns();
        $this->backfillBillingAccounts();
    }

    public function down(): void
    {
        foreach (['credit_purchases', 'workspace_usage_events', 'workspace_credit_wallets', 'workspace_subscriptions', 'workspaces'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'billing_account_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('billing_account_id');
                });
            }
        }

        Schema::dropIfExists('billing_accounts');
    }

    private function createBillingAccounts(): void
    {
        if (Schema::hasTable('billing_accounts')) {
            return;
        }

        Schema::create('billing_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_user_id')->index();
            $table->uuid('primary_workspace_id')->nullable()->index();
            $table->string('name');
            $table->string('plan_id')->default('free')->index();
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function addBillingAccountColumns(): void
    {
        $tables = ['workspaces', 'workspace_subscriptions', 'workspace_credit_wallets', 'workspace_usage_events', 'credit_purchases'];
        foreach ($tables as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'billing_account_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $after = $tableName === 'workspaces' ? 'owner_id' : 'workspace_id';
                $table->uuid('billing_account_id')->nullable()->index()->after($after);
            });
        }
    }

    private function backfillBillingAccounts(): void
    {
        if (!Schema::hasTable('workspaces') || !Schema::hasTable('billing_accounts')) {
            return;
        }

        $workspaces = DB::table('workspaces')->orderBy('created_at')->get();
        $groups = [];

        foreach ($workspaces as $workspace) {
            $owner = trim((string) ($workspace->owner_id ?? '')) ?: 'workspace:' . $workspace->id;
            $groups[$owner][] = $workspace;
        }

        foreach ($groups as $owner => $ownedWorkspaces) {
            $primary = $ownedWorkspaces[0];
            $existing = DB::table('billing_accounts')->where('owner_user_id', $owner)->first();
            $accountId = $existing?->id ?: (string) Str::uuid();
            $workspaceIds = array_map(fn ($workspace) => $workspace->id, $ownedWorkspaces);
            $planId = $this->bestPlanId($ownedWorkspaces);
            $now = now();

            DB::table('billing_accounts')->updateOrInsert(
                ['id' => $accountId],
                [
                    'owner_user_id' => $owner,
                    'primary_workspace_id' => $primary->id,
                    'name' => ($primary->name ?? 'SocialCore') . ' billing',
                    'plan_id' => $planId,
                    'status' => 'active',
                    'metadata' => json_encode([
                        'bootstrap' => true,
                        'shared_billing_migrated_at' => $now->toIso8601String(),
                        'free_welcome_credits_account_scoped' => true,
                    ]),
                    'updated_at' => $now,
                    'created_at' => $existing?->created_at ?? $now,
                ]
            );

            DB::table('workspaces')->whereIn('id', $workspaceIds)->update([
                'billing_account_id' => $accountId,
                'plan_id' => $planId,
                'updated_at' => $now,
            ]);

            foreach (['workspace_usage_events', 'credit_purchases'] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'billing_account_id')) {
                    DB::table($table)->whereIn('workspace_id', $workspaceIds)->update(['billing_account_id' => $accountId]);
                }
            }

            $this->consolidateSubscription($accountId, $primary->id, $workspaceIds, $planId, $now);
            $this->consolidateWallet($accountId, $primary->id, $workspaceIds, $now);
        }
    }

    private function bestPlanId(array $workspaces): string
    {
        $rank = ['free' => 0, 'pro' => 1, 'enterprise' => 2];
        $best = 'free';
        foreach ($workspaces as $workspace) {
            $plan = strtolower(trim((string) ($workspace->plan_id ?? 'free')));
            $plan = match ($plan) {
                'trial', 'starter', 'free_trial' => 'free',
                'pro_trial' => 'pro',
                'enterprise_trial' => 'enterprise',
                default => $plan,
            };
            if (($rank[$plan] ?? 0) > ($rank[$best] ?? 0)) {
                $best = $plan;
            }
        }
        return in_array($best, ['free', 'pro', 'enterprise'], true) ? $best : 'free';
    }

    private function consolidateSubscription(string $accountId, string $primaryWorkspaceId, array $workspaceIds, string $planId, $now): void
    {
        if (!Schema::hasTable('workspace_subscriptions')) {
            return;
        }

        $rows = DB::table('workspace_subscriptions')->whereIn('workspace_id', $workspaceIds)->get();
        $paid = $rows->first(fn ($row) => in_array(strtolower((string) $row->plan_id), ['pro', 'enterprise'], true));
        $source = $paid ?: $rows->first();
        $id = $source?->id ?: (string) Str::uuid();

        DB::table('workspace_subscriptions')->updateOrInsert(
            ['id' => $id],
            [
                'workspace_id' => $primaryWorkspaceId,
                'billing_account_id' => $accountId,
                'plan_id' => $source?->plan_id ?: $planId,
                'status' => $source?->status ?: ($planId === 'free' ? 'trialing' : 'active'),
                'stripe_customer_id' => $source?->stripe_customer_id ?? null,
                'stripe_subscription_id' => $source?->stripe_subscription_id ?? null,
                'current_period_start' => $source?->current_period_start ?? $now,
                'current_period_end' => $source?->current_period_end ?? $now->copy()->addMonth(),
                'trial_ends_at' => $source?->trial_ends_at ?? ($planId === 'free' ? $now->copy()->addDays((int) config('outreach.billing.trial_days', 14)) : null),
                'metadata' => json_encode(array_merge((array) json_decode((string) ($source?->metadata ?? '[]'), true), [
                    'shared_billing_account_id' => $accountId,
                    'shared_billing_canonical' => true,
                ])),
                'created_at' => $source?->created_at ?? $now,
                'updated_at' => $now,
            ]
        );
    }

    private function consolidateWallet(string $accountId, string $primaryWorkspaceId, array $workspaceIds, $now): void
    {
        if (!Schema::hasTable('workspace_credit_wallets')) {
            return;
        }

        $rows = DB::table('workspace_credit_wallets')->whereIn('workspace_id', $workspaceIds)->get();
        $source = $rows->first();
        $id = $source?->id ?: (string) Str::uuid();

        DB::table('workspace_credit_wallets')->updateOrInsert(
            ['id' => $id],
            [
                'workspace_id' => $primaryWorkspaceId,
                'billing_account_id' => $accountId,
                'scrape_credits_balance' => (int) $rows->max('scrape_credits_balance'),
                'ai_credits_balance' => (int) $rows->max('ai_credits_balance'),
                'bonus_scrape_credits' => (int) $rows->sum('bonus_scrape_credits'),
                'bonus_ai_credits' => (int) $rows->sum('bonus_ai_credits'),
                'lifetime_scrape_used' => (int) $rows->sum('lifetime_scrape_used'),
                'lifetime_ai_used' => (int) $rows->sum('lifetime_ai_used'),
                'metadata' => json_encode([
                    'shared_billing_account_id' => $accountId,
                    'shared_billing_canonical' => true,
                    'migration_base_balance_policy' => 'max_not_sum',
                ]),
                'created_at' => $source?->created_at ?? $now,
                'updated_at' => $now,
            ]
        );
    }
};
