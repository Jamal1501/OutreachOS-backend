<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_followers_idx ON creator_profiles (project_id, followers_count)');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_value_created_idx ON creator_profiles (project_id, value_score DESC, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_handle_idx ON creator_profiles (project_id, handle)');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_username_idx ON creator_profiles (project_id, username)');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_lifecycle_followers_idx ON creator_profiles (project_id, lifecycle_state, followers_count)');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_creator_idx ON creator_profiles (project_id, creator_id)');

        DB::statement('CREATE INDEX IF NOT EXISTS creators_project_email_idx ON creators (project_id, primary_email)');
        DB::statement('CREATE INDEX IF NOT EXISTS creators_project_niche_idx ON creators (project_id, niche_category)');

        DB::statement('CREATE INDEX IF NOT EXISTS tasks_project_creator_status_due_idx ON tasks (project_id, creator_profile_id, status, due_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS outreach_events_project_profile_sent_idx ON outreach_events (project_id, creator_profile_id, sent_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS discovery_items_project_duplicate_idx ON discovery_items (project_id, duplicate_key)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS discovery_items_project_duplicate_idx');
        DB::statement('DROP INDEX IF EXISTS outreach_events_project_profile_sent_idx');
        DB::statement('DROP INDEX IF EXISTS tasks_project_creator_status_due_idx');
        DB::statement('DROP INDEX IF EXISTS creators_project_niche_idx');
        DB::statement('DROP INDEX IF EXISTS creators_project_email_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_creator_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_lifecycle_followers_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_username_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_handle_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_value_created_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_followers_idx');
    }
};
