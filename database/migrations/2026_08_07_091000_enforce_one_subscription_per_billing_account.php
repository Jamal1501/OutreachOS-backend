<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BILLING_ACCOUNT_UNIQUE = 'workspace_subscriptions_billing_account_unique';

    private const STRIPE_SUBSCRIPTION_UNIQUE = 'workspace_subscriptions_stripe_subscription_unique';

    public function up(): void
    {
        if (! Schema::hasTable('workspace_subscriptions')) {
            return;
        }

        $this->clearDuplicateValues('billing_account_id');
        $this->clearDuplicateValues('stripe_subscription_id');

        Schema::table('workspace_subscriptions', function (Blueprint $table) {
            $table->unique('billing_account_id', self::BILLING_ACCOUNT_UNIQUE);
            $table->unique('stripe_subscription_id', self::STRIPE_SUBSCRIPTION_UNIQUE);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workspace_subscriptions')) {
            return;
        }

        Schema::table('workspace_subscriptions', function (Blueprint $table) {
            $table->dropUnique(self::BILLING_ACCOUNT_UNIQUE);
            $table->dropUnique(self::STRIPE_SUBSCRIPTION_UNIQUE);
        });
    }

    private function clearDuplicateValues(string $column): void
    {
        if (! Schema::hasColumn('workspace_subscriptions', $column)) {
            return;
        }

        if ($column === 'stripe_subscription_id') {
            DB::table('workspace_subscriptions')
                ->where($column, '')
                ->update([$column => null, 'updated_at' => now()]);
        }

        $duplicates = DB::table('workspace_subscriptions')
            ->select($column)
            ->whereNotNull($column)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($column);

        foreach ($duplicates as $value) {
            $keepId = DB::table('workspace_subscriptions')
                ->where($column, $value)
                ->orderByRaw("CASE WHEN status IN ('active', 'trialing', 'past_due', 'unpaid', 'incomplete') THEN 0 ELSE 1 END")
                ->orderByDesc('updated_at')
                ->value('id');

            DB::table('workspace_subscriptions')
                ->where($column, $value)
                ->where('id', '!=', $keepId)
                ->update([$column => null, 'updated_at' => now()]);
        }
    }
};
