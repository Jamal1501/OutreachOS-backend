<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->foreignId('project_id')->index();
            $table->string('created_by_user_id')->nullable()->index();
            $table->string('original_filename');
            $table->string('status')->default('imported_paused')->index();
            $table->unsignedInteger('row_count')->default(0);
            $table->json('summary')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::create('crm_import_batch_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->index();
            $table->uuid('creator_id')->nullable()->index();
            $table->uuid('creator_profile_id')->nullable()->index();
            $table->string('action');
            $table->json('creator_before')->nullable();
            $table->json('profile_before')->nullable();
            $table->timestamps();

            $table->foreign('batch_id')->references('id')->on('crm_import_batches')->cascadeOnDelete();
            $table->unique(['batch_id', 'creator_profile_id']);
        });

        Schema::table('creator_profiles', function (Blueprint $table) {
            $table->uuid('import_batch_id')->nullable()->index();
            $table->string('assigned_user_id')->nullable()->index();
            $table->timestamp('workflow_paused_at')->nullable()->index();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->uuid('import_batch_id')->nullable()->index();
            $table->string('assigned_user_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['import_batch_id', 'assigned_user_id']);
        });

        Schema::table('creator_profiles', function (Blueprint $table) {
            $table->dropColumn(['import_batch_id', 'assigned_user_id', 'workflow_paused_at']);
        });

        Schema::dropIfExists('crm_import_batch_items');
        Schema::dropIfExists('crm_import_batches');
    }
};
