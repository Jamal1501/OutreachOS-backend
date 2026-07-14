<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plans')) {
            return;
        }

        $plans = [
            'pro' => [
                'monthly_scrape_credits' => 1500,
                'monthly_ai_credits' => 250,
            ],
            'enterprise' => [
                'monthly_scrape_credits' => 3000,
                'monthly_ai_credits' => 800,
            ],
        ];

        foreach ($plans as $planId => $values) {
            DB::table('plans')->where('id', $planId)->update(array_merge($values, [
                'updated_at' => now(),
            ]));
        }

        if (!Schema::hasTable('workspace_credit_wallets') || !Schema::hasTable('workspace_subscriptions')) {
            return;
        }

        $proWorkspaceIds = DB::table('workspace_subscriptions')
            ->where('plan_id', 'pro')
            ->pluck('workspace_id');

        if ($proWorkspaceIds->isNotEmpty()) {
            DB::table('workspace_credit_wallets')
                ->whereIn('workspace_id', $proWorkspaceIds)
                ->where('scrape_credits_balance', '>', 1500)
                ->update(['scrape_credits_balance' => 1500]);
        }

        $enterpriseWorkspaceIds = DB::table('workspace_subscriptions')
            ->where('plan_id', 'enterprise')
            ->pluck('workspace_id');

        if ($enterpriseWorkspaceIds->isNotEmpty()) {
            DB::table('workspace_credit_wallets')
                ->whereIn('workspace_id', $enterpriseWorkspaceIds)
                ->where('scrape_credits_balance', '>', 3000)
                ->update(['scrape_credits_balance' => 3000]);

            DB::table('workspace_credit_wallets')
                ->whereIn('workspace_id', $enterpriseWorkspaceIds)
                ->where('ai_credits_balance', '>', 800)
                ->update(['ai_credits_balance' => 800]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('plans')) {
            return;
        }

        DB::table('plans')->where('id', 'pro')->update([
            'monthly_scrape_credits' => 3500,
            'monthly_ai_credits' => 250,
            'updated_at' => now(),
        ]);

        DB::table('plans')->where('id', 'enterprise')->update([
            'monthly_scrape_credits' => 12000,
            'monthly_ai_credits' => 1200,
            'updated_at' => now(),
        ]);
    }
};
