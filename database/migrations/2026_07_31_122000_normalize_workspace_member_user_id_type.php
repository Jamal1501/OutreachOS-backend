<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasColumn('workspace_members', 'user_id')) {
            return;
        }

        $column = DB::selectOne(<<<'SQL'
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'workspace_members'
              AND column_name = 'user_id'
        SQL);

        if (($column->data_type ?? null) === 'uuid') {
            DB::statement(<<<'SQL'
                ALTER TABLE workspace_members
                ALTER COLUMN user_id TYPE VARCHAR(255)
                USING user_id::text
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasColumn('workspace_members', 'user_id')) {
            return;
        }

        $invalidUserIds = (int) DB::table('workspace_members')
            ->whereNotNull('user_id')
            ->whereRaw("user_id !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'")
            ->count();

        if ($invalidUserIds === 0) {
            DB::statement(<<<'SQL'
                ALTER TABLE workspace_members
                ALTER COLUMN user_id TYPE UUID
                USING user_id::uuid
            SQL);
        }
    }
};
