<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Some production databases use UUID here while users.supabase_user_id
        // is VARCHAR. Supabase RLS policies depend on this column and prevent a
        // safe in-place type change. Application joins explicitly cast the UUID
        // to text, preserving both compatibility and the existing RLS policies.
    }

    public function down(): void
    {
        // No schema mutation is performed in up().
    }
};
