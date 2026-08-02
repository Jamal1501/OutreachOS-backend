<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_incident_banners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->boolean('enabled')->default(false)->index();
            $table->string('severity', 20)->default('warning');
            $table->string('message', 500);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('updated_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_incident_banners');
    }
};
