<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('duplicate_links')) {
            Schema::create('duplicate_links', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->string('project_id')->index();
                $table->string('creator_a_handle');
                $table->string('creator_a_platform', 80);
                $table->string('creator_b_handle');
                $table->string('creator_b_platform', 80);
                $table->decimal('confidence', 5, 2)->default(0);
                $table->json('match_signals')->nullable();
                $table->string('status', 40)->default('pending')->index();
                $table->timestamp('merged_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['workspace_id', 'project_id', 'status']);
            });
        } else {
            Schema::table('duplicate_links', function (Blueprint $table) {
                if (!Schema::hasColumn('duplicate_links', 'workspace_id')) {
                    $table->uuid('workspace_id')->nullable()->index()->after('id');
                }
                if (!Schema::hasColumn('duplicate_links', 'project_id')) {
                    $table->string('project_id')->index()->after('workspace_id');
                }
                if (!Schema::hasColumn('duplicate_links', 'status')) {
                    $table->string('status', 40)->default('pending')->index();
                }
                if (!Schema::hasColumn('duplicate_links', 'merged_at')) {
                    $table->timestamp('merged_at')->nullable();
                }
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE duplicate_links DROP CONSTRAINT IF EXISTS duplicate_links_status_check");
            DB::statement("ALTER TABLE duplicate_links ADD CONSTRAINT duplicate_links_status_check CHECK (status IN ('pending', 'confirmed', 'rejected', 'merged', 'linked'))");
            DB::statement('CREATE INDEX IF NOT EXISTS duplicate_links_workspace_project_status_idx ON duplicate_links (workspace_id, project_id, status)');
        }
    }

    public function down(): void
    {
        // Keep the table. This migration may upgrade legacy Supabase-created data.
        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('duplicate_links')) {
            DB::statement("ALTER TABLE duplicate_links DROP CONSTRAINT IF EXISTS duplicate_links_status_check");
        }
    }
};
