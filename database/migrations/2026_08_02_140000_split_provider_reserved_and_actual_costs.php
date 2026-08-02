<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_usage_events', function (Blueprint $table) {
            $table->decimal('provider_cost_reserved_usd', 10, 4)->nullable();
            $table->decimal('provider_cost_actual_usd', 10, 4)->nullable();
        });

        DB::table('workspace_usage_events')
            ->whereNotNull('provider_cost_usd')
            ->update([
                'provider_cost_reserved_usd' => DB::raw('provider_cost_usd'),
            ]);

        // Historical rows did not record whether a refunded run had already reached the
        // provider. Keep their previous value as actual spend rather than guessing zero;
        // all new settlements write an explicit actual amount.
        DB::table('workspace_usage_events')
            ->whereIn('status', ['consumed', 'refunded'])
            ->whereNotNull('provider_cost_usd')
            ->update([
                'provider_cost_actual_usd' => DB::raw('provider_cost_usd'),
            ]);
    }

    public function down(): void
    {
        Schema::table('workspace_usage_events', function (Blueprint $table) {
            $table->dropColumn(['provider_cost_reserved_usd', 'provider_cost_actual_usd']);
        });
    }
};
