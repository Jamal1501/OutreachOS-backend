<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table) {
                if (!Schema::hasColumn('tasks', 'group_key')) {
                    $table->string('group_key')->nullable()->index();
                }
                if (!Schema::hasColumn('tasks', 'group_label')) {
                    $table->string('group_label')->nullable();
                }
                if (!Schema::hasColumn('tasks', 'group_type')) {
                    $table->string('group_type')->nullable()->index();
                }
                if (!Schema::hasColumn('tasks', 'completion_outcome')) {
                    $table->string('completion_outcome')->nullable()->index();
                }
                if (!Schema::hasColumn('tasks', 'skip_reason')) {
                    $table->string('skip_reason')->nullable()->index();
                }
                if (!Schema::hasColumn('tasks', 'skip_reason_detail')) {
                    $table->text('skip_reason_detail')->nullable();
                }
                if (!Schema::hasColumn('tasks', 'snooze_reason')) {
                    $table->string('snooze_reason')->nullable();
                }
                if (!Schema::hasColumn('tasks', 'actionable_channel')) {
                    $table->string('actionable_channel')->nullable()->index();
                }
                if (!Schema::hasColumn('tasks', 'external_channel')) {
                    $table->string('external_channel')->nullable()->index();
                }
                if (!Schema::hasColumn('tasks', 'conversation_url')) {
                    $table->text('conversation_url')->nullable();
                }
                if (!Schema::hasColumn('tasks', 'waiting_until')) {
                    $table->timestamp('waiting_until')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('creator_profiles')) {
            Schema::table('creator_profiles', function (Blueprint $table) {
                if (!Schema::hasColumn('creator_profiles', 'automation_state')) {
                    $table->json('automation_state')->nullable();
                }
                if (!Schema::hasColumn('creator_profiles', 'conversation_channel')) {
                    $table->string('conversation_channel')->nullable()->index();
                }
                if (!Schema::hasColumn('creator_profiles', 'conversation_url')) {
                    $table->text('conversation_url')->nullable();
                }
                if (!Schema::hasColumn('creator_profiles', 'last_outreach_channel')) {
                    $table->string('last_outreach_channel')->nullable()->index();
                }
                if (!Schema::hasColumn('creator_profiles', 'last_outreach_at')) {
                    $table->timestamp('last_outreach_at')->nullable()->index();
                }
                if (!Schema::hasColumn('creator_profiles', 'follow_up_due_at')) {
                    $table->timestamp('follow_up_due_at')->nullable()->index();
                }
                if (!Schema::hasColumn('creator_profiles', 'next_action_at')) {
                    $table->timestamp('next_action_at')->nullable()->index();
                }
                if (!Schema::hasColumn('creator_profiles', 'waiting_until')) {
                    $table->timestamp('waiting_until')->nullable()->index();
                }
                if (!Schema::hasColumn('creator_profiles', 'task_suppressed_until')) {
                    $table->timestamp('task_suppressed_until')->nullable()->index();
                }
                if (!Schema::hasColumn('creator_profiles', 'last_task_outcome')) {
                    $table->string('last_task_outcome')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table) {
                $drops = [];
                foreach (['group_key','group_label','group_type','completion_outcome','skip_reason','skip_reason_detail','snooze_reason','actionable_channel','external_channel','conversation_url','waiting_until'] as $column) {
                    if (Schema::hasColumn('tasks', $column)) {
                        $drops[] = $column;
                    }
                }
                if ($drops !== []) {
                    $table->dropColumn($drops);
                }
            });
        }

        if (Schema::hasTable('creator_profiles')) {
            Schema::table('creator_profiles', function (Blueprint $table) {
                $drops = [];
                foreach (['automation_state','conversation_channel','conversation_url','last_outreach_channel','last_outreach_at','follow_up_due_at','next_action_at','waiting_until','task_suppressed_until','last_task_outcome'] as $column) {
                    if (Schema::hasColumn('creator_profiles', $column)) {
                        $drops[] = $column;
                    }
                }
                if ($drops !== []) {
                    $table->dropColumn($drops);
                }
            });
        }
    }
};
