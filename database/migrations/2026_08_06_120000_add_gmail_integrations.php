<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table) {
            $table->text('oauth_credentials')->nullable();
            $table->string('connected_by_user_id')->nullable()->index();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unique(
                ['project_id', 'platform', 'provider', 'external_account_id'],
                'connected_accounts_provider_identity_unique'
            );
        });

        Schema::create('oauth_connection_states', function (Blueprint $table) {
            $table->string('state_hash', 64)->primary();
            $table->uuid('workspace_id')->index();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('user_id')->index();
            $table->string('provider')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('outbound_email_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connected_account_id')->constrained('connected_accounts')->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->string('sent_by_user_id')->index();
            $table->string('recipient_email');
            $table->string('subject', 998);
            $table->string('status')->default('sending')->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('provider_thread_id')->nullable();
            $table->string('error_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_email_deliveries');
        Schema::dropIfExists('oauth_connection_states');

        Schema::table('connected_accounts', function (Blueprint $table) {
            $table->dropUnique('connected_accounts_provider_identity_unique');
            $table->dropColumn([
                'oauth_credentials',
                'connected_by_user_id',
                'token_expires_at',
                'last_used_at',
                'last_error',
            ]);
        });
    }
};
