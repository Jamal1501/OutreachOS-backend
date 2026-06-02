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

        DB::statement('CREATE INDEX IF NOT EXISTS tasks_project_template_completed_idx ON tasks (project_id, message_template_id, completed_at DESC) WHERE message_template_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS outreach_events_project_template_sent_idx ON outreach_events (project_id, message_template_id, sent_at DESC) WHERE message_template_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS creator_profiles_project_responded_idx ON creator_profiles (project_id, responded_at DESC) WHERE responded_at IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS creator_profiles_project_responded_idx');
        DB::statement('DROP INDEX IF EXISTS outreach_events_project_template_sent_idx');
        DB::statement('DROP INDEX IF EXISTS tasks_project_template_completed_idx');
    }
};
