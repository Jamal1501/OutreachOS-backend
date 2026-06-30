<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workspace_audit_events')) {
            return;
        }

        Schema::create('workspace_audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('actor_user_id')->nullable()->index();
            $table->string('event_type')->index();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['workspace_id', 'event_type', 'created_at'], 'workspace_audit_workspace_event_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_audit_events');
    }
};
