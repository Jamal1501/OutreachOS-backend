<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('angle_id');
            $table->string('platform');
            $table->string('niche')->nullable();
            $table->string('stage')->default('cold_invite');
            $table->longText('copy');
            $table->text('notes')->nullable();
            $table->text('psychological_trigger')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'platform', 'stage']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('creator_profile_id')->nullable();
            $table->foreign('creator_profile_id')->references('id')->on('creator_profiles')->nullOnDelete();
            $table->uuid('message_template_id')->nullable();
            $table->foreign('message_template_id')->references('id')->on('message_templates')->nullOnDelete();
            $table->string('external_task_key')->nullable()->index();
            $table->string('platform')->nullable();
            $table->string('handle')->nullable();
            $table->string('task_type');
            $table->string('priority')->default('LOW');
            $table->string('status')->default('PENDING');
            $table->timestamp('due_at')->nullable();
            $table->text('open_url')->nullable();
            $table->longText('message_draft')->nullable();
            $table->string('source_provider')->nullable();
            $table->string('source_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status', 'due_at']);
        });

        Schema::create('outreach_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('creator_profile_id')->nullable();
            $table->foreign('creator_profile_id')->references('id')->on('creator_profiles')->nullOnDelete();
            $table->uuid('task_id')->nullable();
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
            $table->uuid('message_template_id')->nullable();
            $table->foreign('message_template_id')->references('id')->on('message_templates')->nullOnDelete();
            $table->string('external_event_key')->nullable()->index();
            $table->string('platform')->nullable();
            $table->string('handle')->nullable();
            $table->string('channel')->nullable();
            $table->string('event_type');
            $table->string('sender_account')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('status')->nullable();
            $table->text('url')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'event_type', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_events');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('message_templates');
    }
};
