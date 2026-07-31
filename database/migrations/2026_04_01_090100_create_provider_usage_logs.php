<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_usage_logs')) {
            Schema::create('ai_usage_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->foreignId('project_id')->nullable()->index();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('supabase_user_id')->nullable()->index();
                $table->string('provider')->default('openai');
                $table->string('model')->nullable();
                $table->string('operation')->nullable();
                $table->unsignedInteger('prompt_tokens')->nullable();
                $table->unsignedInteger('completion_tokens')->nullable();
                $table->unsignedInteger('total_tokens')->nullable();
                $table->decimal('estimated_cost_usd', 10, 4)->nullable();
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('apify_usage_logs')) {
            Schema::create('apify_usage_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->foreignId('project_id')->nullable()->index();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('supabase_user_id')->nullable()->index();
                $table->string('actor_id')->nullable();
                $table->string('actor_key')->nullable();
                $table->string('run_id')->nullable()->index();
                $table->string('dataset_id')->nullable()->index();
                $table->string('status')->nullable();
                $table->decimal('max_total_charge_usd', 10, 4)->nullable();
                $table->decimal('estimated_cost_usd', 10, 4)->nullable();
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('apify_usage_logs');
        Schema::dropIfExists('ai_usage_logs');
    }
};
