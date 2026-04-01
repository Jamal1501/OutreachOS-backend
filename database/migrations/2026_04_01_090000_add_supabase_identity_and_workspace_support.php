<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'supabase_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('supabase_user_id')->nullable()->unique()->after('id');
            });
        }

        if (!Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->unsignedInteger('max_members')->default(1);
                $table->unsignedInteger('max_creators')->default(100);
                $table->json('features')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('workspaces')) {
            Schema::create('workspaces', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('owner_id')->nullable();
                $table->string('plan_id')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('workspace_members')) {
            Schema::create('workspace_members', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->string('user_id');
                $table->string('role')->default('member');
                $table->timestamp('joined_at')->nullable();
                $table->unique(['workspace_id', 'user_id']);
                $table->index(['user_id', 'workspace_id']);
            });
        }

        if (!Schema::hasTable('workspace_invitations')) {
            Schema::create('workspace_invitations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->string('email');
                $table->string('role')->default('member');
                $table->string('token')->unique();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('projects') && !Schema::hasColumn('projects', 'workspace_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->uuid('workspace_id')->nullable()->after('id')->index();
                $table->index(['workspace_id', 'workbook_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'workspace_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropIndex(['workspace_id', 'workbook_id']);
                $table->dropColumn('workspace_id');
            });
        }

        if (Schema::hasTable('workspace_invitations')) {
            Schema::dropIfExists('workspace_invitations');
        }

        if (Schema::hasTable('workspace_members')) {
            Schema::dropIfExists('workspace_members');
        }

        if (Schema::hasTable('workspaces')) {
            Schema::dropIfExists('workspaces');
        }

        if (Schema::hasTable('plans')) {
            Schema::dropIfExists('plans');
        }

        if (Schema::hasColumn('users', 'supabase_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['supabase_user_id']);
                $table->dropColumn('supabase_user_id');
            });
        }
    }
};
