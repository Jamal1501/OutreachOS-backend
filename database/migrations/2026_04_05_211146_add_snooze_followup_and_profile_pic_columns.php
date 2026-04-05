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
                if (!Schema::hasColumn('tasks', 'snoozed_until')) {
                    $table->timestamp('snoozed_until')->nullable();
                }
                if (!Schema::hasColumn('tasks', 'follow_up_count')) {
                    $table->smallInteger('follow_up_count')->default(0);
                }
                if (!Schema::hasColumn('tasks', 'platform_connection_state')) {
                    $table->string('platform_connection_state')->nullable();
                }
            });
        }

        if (Schema::hasTable('creator_profiles')) {
            Schema::table('creator_profiles', function (Blueprint $table) {
                if (!Schema::hasColumn('creator_profiles', 'profile_pic_url')) {
                    $table->text('profile_pic_url')->nullable();
                }
                if (!Schema::hasColumn('creator_profiles', 'comment_attempted_at')) {
                    $table->timestamp('comment_attempted_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table) {
                $drops = [];
                if (Schema::hasColumn('tasks', 'snoozed_until')) {
                    $drops[] = 'snoozed_until';
                }
                if (Schema::hasColumn('tasks', 'follow_up_count')) {
                    $drops[] = 'follow_up_count';
                }
                if (Schema::hasColumn('tasks', 'platform_connection_state')) {
                    $drops[] = 'platform_connection_state';
                }
                if ($drops !== []) {
                    $table->dropColumn($drops);
                }
            });
        }

        if (Schema::hasTable('creator_profiles')) {
            Schema::table('creator_profiles', function (Blueprint $table) {
                $drops = [];
                if (Schema::hasColumn('creator_profiles', 'profile_pic_url')) {
                    $drops[] = 'profile_pic_url';
                }
                if (Schema::hasColumn('creator_profiles', 'comment_attempted_at')) {
                    $drops[] = 'comment_attempted_at';
                }
                if ($drops !== []) {
                    $table->dropColumn($drops);
                }
            });
        }
    }
};
