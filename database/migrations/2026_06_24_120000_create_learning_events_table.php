<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_key')->nullable()->index();
            $table->string('source_type')->index();
            $table->string('source_id')->nullable()->index();
            $table->string('event_name')->index();
            $table->string('event_group')->nullable()->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('actor_user_id')->nullable()->index();
            $table->uuid('creator_profile_id')->nullable()->index();
            $table->uuid('task_id')->nullable()->index();
            $table->uuid('message_template_id')->nullable()->index();
            $table->string('platform')->nullable()->index();
            $table->string('handle')->nullable()->index();
            $table->string('channel')->nullable();
            $table->string('outcome_label')->nullable()->index();
            $table->string('status')->nullable()->index();
            $table->json('creator_snapshot')->nullable();
            $table->json('task_snapshot')->nullable();
            $table->json('template_snapshot')->nullable();
            $table->json('message_snapshot')->nullable();
            $table->json('context')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'event_name'], 'learning_events_source_event_unique');
            $table->index(['workspace_id', 'event_name', 'occurred_at'], 'learning_events_workspace_event_time_idx');
            $table->index(['project_id', 'event_group', 'occurred_at'], 'learning_events_project_group_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_events');
    }
};
