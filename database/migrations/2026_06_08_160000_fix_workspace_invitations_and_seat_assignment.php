<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workspace_invitations')) {
            Schema::table('workspace_invitations', function (Blueprint $table) {
                if (!Schema::hasColumn('workspace_invitations', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('workspace_invitations', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('workspace_invitations')) {
            Schema::table('workspace_invitations', function (Blueprint $table) {
                if (Schema::hasColumn('workspace_invitations', 'updated_at')) {
                    $table->dropColumn('updated_at');
                }
            });
        }
    }
};
