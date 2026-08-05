<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('assigned_by_user_id')->nullable()->index();
            $table->timestamp('assigned_at')->nullable()->index();
            $table->string('completed_by_user_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['assigned_by_user_id', 'assigned_at', 'completed_by_user_id']);
        });
    }
};
