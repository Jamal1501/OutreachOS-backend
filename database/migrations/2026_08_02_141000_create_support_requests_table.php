<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 32)->unique();
            $table->uuid('workspace_id')->index();
            $table->string('user_id')->index();
            $table->string('email')->nullable();
            $table->string('category', 64);
            $table->string('subject', 160);
            $table->text('message');
            $table->string('page', 500)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('delivery_attempts')->default(0);
            $table->text('last_delivery_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
    }
};
