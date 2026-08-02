<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_spend_controls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 40)->index();
            $table->string('scope_key')->index();
            $table->uuid('workspace_id')->nullable()->index();
            $table->decimal('daily_limit_usd', 12, 4)->nullable();
            $table->decimal('override_limit_usd', 12, 4)->nullable();
            $table->timestamp('override_until')->nullable()->index();
            $table->string('override_reason')->nullable();
            $table->string('updated_by_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'scope_key']);
        });

        Schema::create('provider_spend_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();
            $table->string('provider', 40)->index();
            $table->string('blocked_scope', 40)->index();
            $table->decimal('requested_usd', 12, 4);
            $table->decimal('current_spend_usd', 12, 4);
            $table->decimal('projected_spend_usd', 12, 4);
            $table->decimal('daily_limit_usd', 12, 4);
            $table->string('reason_code')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_spend_blocks');
        Schema::dropIfExists('provider_spend_controls');
    }
};
