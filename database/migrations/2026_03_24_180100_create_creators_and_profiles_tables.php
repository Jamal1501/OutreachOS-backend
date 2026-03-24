<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('external_identity_key')->nullable()->index();
            $table->string('display_name')->nullable();
            $table->string('primary_email')->nullable()->index();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('primary_language')->nullable();
            $table->string('niche_category')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'display_name']);
        });

        Schema::create('creator_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('creator_id');
            $table->foreign('creator_id')->references('id')->on('creators')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('handle');
            $table->string('username')->nullable();
            $table->text('profile_url')->nullable();
            $table->text('dm_link')->nullable();
            $table->string('status')->default('DISCOVERED');
            $table->string('lifecycle_state')->default('discovered');
            $table->unsignedBigInteger('followers_count')->nullable();
            $table->decimal('engagement_rate_pct', 8, 2)->nullable();
            $table->string('preferred_channel')->nullable();
            $table->timestamp('last_content_at')->nullable();
            $table->unsignedInteger('value_score')->nullable();
            $table->string('value_bar')->nullable();
            $table->string('duplicate_flag')->nullable();
            $table->boolean('accepted_flag')->default(false);
            $table->boolean('follow_up_needed')->default(false);
            $table->timestamp('dm_sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('source_provider')->nullable();
            $table->string('source_reference')->nullable();
            $table->json('source_metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'platform', 'handle'], 'creator_profiles_project_platform_handle_unique');
            $table->index(['project_id', 'platform', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_profiles');
        Schema::dropIfExists('creators');
    }
};
