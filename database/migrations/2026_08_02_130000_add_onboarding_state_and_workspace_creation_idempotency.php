<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->uuid('creation_request_id')->nullable()->unique();
        });

        Schema::create('workspace_user_onboarding_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('user_id')->index();
            $table->unsignedInteger('version')->default(2);
            $table->json('dismissed_routes')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->string('last_route', 120)->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_user_onboarding_states');

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropUnique(['creation_request_id']);
            $table->dropColumn('creation_request_id');
        });
    }
};
