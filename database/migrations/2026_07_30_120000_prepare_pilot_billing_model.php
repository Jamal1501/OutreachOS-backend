<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plans')) {
            DB::table('plans')->where('id', 'free')->update($this->existingColumns('plans', [
                'name' => 'Evaluation',
                'trial_scrape_credits' => 25,
                'trial_ai_credits' => 5,
                'topup_price_multiplier' => 1.0,
                'updated_at' => now(),
            ]));
            DB::table('plans')->where('id', 'pro')->update($this->existingColumns('plans', [
                'name' => 'Pro',
                'topup_price_multiplier' => 1.0,
                'updated_at' => now(),
            ]));
            DB::table('plans')->where('id', 'enterprise')->update($this->existingColumns('plans', [
                'name' => 'Agency',
                'topup_price_multiplier' => 1.0,
                'updated_at' => now(),
            ]));
        }

        if (Schema::hasTable('credit_packages')) {
            $packages = [
                '11111111-1111-4111-8111-111111111111' => ['name' => 'Extra Workflow Pack', 'price' => 15.00, 'plans' => ['pro', 'enterprise']],
                '22222222-2222-4222-8222-222222222222' => ['name' => 'Growth Workflow Pack', 'price' => 49.00, 'plans' => ['pro', 'enterprise']],
                '33333333-3333-4333-8333-333333333333' => ['name' => 'Scale Workflow Pack', 'price' => 119.00, 'plans' => ['pro', 'enterprise']],
            ];

            foreach ($packages as $id => $package) {
                DB::table('credit_packages')->where('id', $id)->update($this->existingColumns('credit_packages', [
                    'name' => $package['name'],
                    'price_usd' => $package['price'],
                    'allowed_plan_ids' => json_encode($package['plans']),
                    'updated_at' => now(),
                ]));
            }
        }

        if (Schema::hasTable('workspace_subscriptions')) {
            DB::table('workspace_subscriptions')
                ->where('plan_id', 'free')
                ->whereIn('status', ['trialing', 'trial_expired'])
                ->update($this->existingColumns('workspace_subscriptions', [
                    'status' => 'active',
                    'trial_ends_at' => null,
                    'updated_at' => now(),
                ]));
        }

        if (Schema::hasTable('workspace_credit_wallets') && Schema::hasTable('billing_accounts')) {
            DB::table('workspace_credit_wallets')
                ->whereIn('billing_account_id', DB::table('billing_accounts')->where('plan_id', 'free')->select('id'))
                ->where('scrape_credits_balance', '>', 25)
                ->update($this->existingColumns('workspace_credit_wallets', [
                    'scrape_credits_balance' => 25,
                    'updated_at' => now(),
                ]));

            DB::table('workspace_credit_wallets')
                ->whereIn('billing_account_id', DB::table('billing_accounts')->where('plan_id', 'free')->select('id'))
                ->where('ai_credits_balance', '>', 5)
                ->update($this->existingColumns('workspace_credit_wallets', [
                    'ai_credits_balance' => 5,
                    'updated_at' => now(),
                ]));
        }
    }

    public function down(): void
    {
        // Pilot pricing and already-consumed evaluation allowances are intentionally not restored.
    }

    private function existingColumns(string $table, array $values): array
    {
        return array_filter(
            $values,
            fn (mixed $value, string $column) => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH,
        );
    }
};
