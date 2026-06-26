<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_relationship_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('creator_profile_id');
            $table->foreign('creator_profile_id')->references('id')->on('creator_profiles')->cascadeOnDelete();
            $table->uuid('outreach_event_id')->nullable();
            $table->foreign('outreach_event_id')->references('id')->on('outreach_events')->nullOnDelete();
            $table->uuid('task_id')->nullable();
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
            $table->string('source_type');
            $table->string('source_id')->nullable();
            $table->string('event_type');
            $table->string('channel')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('actor_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'creator_profile_id', 'occurred_at'], 'creator_relationship_events_workspace_profile_time_idx');
            $table->index(['project_id', 'creator_profile_id', 'occurred_at'], 'creator_relationship_events_project_profile_time_idx');
            $table->unique(['workspace_id', 'source_type', 'source_id', 'event_type'], 'creator_relationship_events_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_relationship_events');
    }
};
