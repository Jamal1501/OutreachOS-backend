<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_lifecycle_created_idx ON creator_profiles (project_id, lifecycle_state, created_at DESC, id)');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_platform_created_idx ON creator_profiles (project_id, platform, created_at DESC, id)');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_followers_idx ON creator_profiles (project_id, followers_count DESC, id)');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_engagement_idx ON creator_profiles (project_id, engagement_rate_pct DESC, id)');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_value_score_idx ON creator_profiles (project_id, value_score DESC, id)');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_created_idx ON creator_profiles (project_id, created_at DESC, id)');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_creator_idx ON creator_profiles (project_id, creator_id)');
        DB::statement("CREATE INDEX IF NOT EXISTS creators_project_email_present_idx ON creators (project_id, primary_email) WHERE primary_email IS NOT NULL AND BTRIM(primary_email) <> ''");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS creators_project_email_present_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_creator_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_created_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_value_score_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_engagement_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_followers_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_platform_created_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_lifecycle_created_idx');
    }
};
