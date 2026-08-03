<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropUnique(['creation_request_id']);
            $table->unique(['owner_id', 'creation_request_id']);
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropUnique(['owner_id', 'creation_request_id']);
            $table->unique('creation_request_id');
        });
    }
};
