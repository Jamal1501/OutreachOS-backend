<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_suppressions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('platform')->nullable()->index();
            $table->string('normalized_handle')->nullable()->index();
            $table->string('email_hash', 64)->nullable()->index();
            $table->string('source')->default('privacy_request');
            $table->text('reason')->nullable();
            $table->string('created_by_user_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['platform', 'normalized_handle'], 'creator_suppressions_platform_handle_unique');
            $table->unique('email_hash', 'creator_suppressions_email_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_suppressions');
    }
};
