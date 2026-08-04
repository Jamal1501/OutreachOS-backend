<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_import_batch_items', function (Blueprint $table) {
            $table->uuid('message_template_id')->nullable()->index();
            $table->json('template_before')->nullable();
            $table->json('template_after')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_import_batch_items', function (Blueprint $table) {
            $table->dropColumn(['message_template_id', 'template_before', 'template_after']);
        });
    }
};
