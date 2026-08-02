<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_requests', function (Blueprint $table) {
            $table->string('ticket_status', 32)->default('open')->index();
            $table->string('updated_by_operator_id')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_requests', function (Blueprint $table) {
            $table->dropColumn(['ticket_status', 'updated_by_operator_id', 'resolved_at']);
        });
    }
};
