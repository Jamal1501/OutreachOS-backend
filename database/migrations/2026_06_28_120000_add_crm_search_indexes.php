<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement("CREATE INDEX IF NOT EXISTS creator_profiles_handle_trgm_idx ON creator_profiles USING gin (LOWER(COALESCE(handle, '')) gin_trgm_ops)");
        DB::statement("CREATE INDEX IF NOT EXISTS creator_profiles_username_trgm_idx ON creator_profiles USING gin (LOWER(COALESCE(username, '')) gin_trgm_ops)");
        DB::statement("CREATE INDEX IF NOT EXISTS creators_display_name_trgm_idx ON creators USING gin (LOWER(COALESCE(display_name, '')) gin_trgm_ops)");
        DB::statement("CREATE INDEX IF NOT EXISTS creators_primary_email_trgm_idx ON creators USING gin (LOWER(COALESCE(primary_email, '')) gin_trgm_ops)");
        DB::statement("CREATE INDEX IF NOT EXISTS creators_niche_category_trgm_idx ON creators USING gin (LOWER(COALESCE(niche_category, '')) gin_trgm_ops)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS creators_niche_category_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS creators_primary_email_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS creators_display_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_username_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS creator_profiles_handle_trgm_idx');
    }
};
