<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_spend_control_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 32)->index();
            $table->string('scope_key')->index();
            $table->uuid('workspace_id')->nullable()->index();
            $table->string('updated_by_user_id')->nullable()->index();
            $table->json('before_values')->nullable();
            $table->json('after_values');
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_spend_control_audits');
    }
};
