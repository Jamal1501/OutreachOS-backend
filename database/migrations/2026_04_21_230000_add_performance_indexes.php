<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_created_at_idx ON creator_profiles (project_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_platform_lifecycle_created_idx ON creator_profiles (project_id, platform, lifecycle_state, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_lifecycle_value_idx ON creator_profiles (project_id, lifecycle_state, value_score DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS discovery_items_project_platform_discovered_idx ON discovery_items (project_id, platform, discovered_at DESC, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS discovery_runs_project_status_updated_idx ON discovery_runs (project_id, status, updated_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS tasks_project_due_status_idx ON tasks (project_id, due_at, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS outreach_events_project_sent_at_idx ON outreach_events (project_id, sent_at DESC)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS outreach_events_project_sent_at_idx');
        DB::statement('DROP INDEX IF EXISTS tasks_project_due_status_idx');
        DB::statement('DROP INDEX IF EXISTS discovery_runs_project_status_updated_idx');
        DB::statement('DROP INDEX IF EXISTS discovery_items_project_platform_discovered_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_lifecycle_value_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_platform_lifecycle_created_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_created_at_idx');
    }
};
