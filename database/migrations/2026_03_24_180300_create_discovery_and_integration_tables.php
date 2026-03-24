<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('provider');
            $table->string('provider_run_id')->nullable()->index();
            $table->string('provider_dataset_id')->nullable()->index();
            $table->string('status')->default('pending');
            $table->string('current_step')->nullable();
            $table->json('hashtags')->nullable();
            $table->unsignedInteger('discovery_limit')->nullable();
            $table->unsignedInteger('enrichment_limit')->nullable();
            $table->boolean('dedupe_against_crm')->default(true);
            $table->json('request_payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'platform', 'status']);
        });

        Schema::create('discovery_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('discovery_run_id')->nullable();
            $table->foreign('discovery_run_id')->references('id')->on('discovery_runs')->nullOnDelete();
            $table->string('platform');
            $table->string('external_post_id')->nullable();
            $table->string('handle')->nullable();
            $table->string('username')->nullable();
            $table->string('full_name')->nullable();
            $table->text('profile_url')->nullable();
            $table->text('post_url')->nullable();
            $table->longText('caption')->nullable();
            $table->json('hashtags')->nullable();
            $table->json('metrics')->nullable();
            $table->string('duplicate_key')->nullable()->index();
            $table->string('recommended_action')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('promoted_to_enrichment_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'platform', 'handle']);
        });

        Schema::create('enrichment_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('discovery_run_id')->nullable();
            $table->foreign('discovery_run_id')->references('id')->on('discovery_runs')->nullOnDelete();
            $table->string('platform');
            $table->string('provider');
            $table->string('provider_run_id')->nullable()->index();
            $table->string('provider_dataset_id')->nullable()->index();
            $table->string('status')->default('pending');
            $table->json('input_urls')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'platform', 'status']);
        });

        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('provider');
            $table->string('external_account_id')->nullable();
            $table->string('username')->nullable();
            $table->string('status')->default('disconnected');
            $table->json('scopes')->nullable();
            $table->string('credentials_reference')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'platform', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_accounts');
        Schema::dropIfExists('enrichment_jobs');
        Schema::dropIfExists('discovery_items');
        Schema::dropIfExists('discovery_runs');
    }
};
