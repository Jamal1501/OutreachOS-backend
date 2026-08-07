<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stripe_webhook_events')) {
            return;
        }

        $needsAttemptCount = ! Schema::hasColumn('stripe_webhook_events', 'attempt_count');
        $needsLastAttemptAt = ! Schema::hasColumn('stripe_webhook_events', 'last_attempt_at');

        Schema::table('stripe_webhook_events', function (Blueprint $table) use ($needsAttemptCount, $needsLastAttemptAt) {
            if ($needsAttemptCount) {
                $table->unsignedSmallInteger('attempt_count')->default(1)->after('status');
            }
            if ($needsLastAttemptAt) {
                $table->timestamp('last_attempt_at')->nullable()->after('attempt_count');
            }
        });

        DB::table('stripe_webhook_events')
            ->whereNull('last_attempt_at')
            ->update([
                'attempt_count' => 1,
                'last_attempt_at' => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('stripe_webhook_events')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('stripe_webhook_events', 'last_attempt_at') ? 'last_attempt_at' : null,
            Schema::hasColumn('stripe_webhook_events', 'attempt_count') ? 'attempt_count' : null,
        ]));

        Schema::table('stripe_webhook_events', function (Blueprint $table) use ($columns) {
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
