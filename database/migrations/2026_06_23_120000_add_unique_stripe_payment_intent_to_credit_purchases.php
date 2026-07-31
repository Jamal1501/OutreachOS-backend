<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('credit_purchases')) {
            return;
        }

        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->unique('stripe_payment_intent_id', 'credit_purchases_stripe_payment_intent_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('credit_purchases')) {
            return;
        }

        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->dropUnique('credit_purchases_stripe_payment_intent_unique');
        });
    }
};
