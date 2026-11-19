<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Amounts were stored as strings, which compare lexicographically: "50"
     * sorts above "1000". They become decimals so the amount filters and
     * ordering are numeric, and the columns the API filters on are indexed.
     */
    public function up(): void
    {
        Schema::table('mpesa_confirmations', function (Blueprint $table) {
            $table->decimal('transaction_amount', 12, 2)->nullable()->change();
            $table->decimal('org_balance', 14, 2)->nullable()->change();

            $table->index('transaction_id');
            $table->index('business_shortcode');
            $table->index('mobile_number');
            $table->index('billref_no');
            $table->index('transaction_time');
        });

        Schema::table('mpesa_stk_payments', function (Blueprint $table) {
            $table->index('transaction_id');
            $table->index('msisdn');
            $table->index('business_shortcode');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mpesa_confirmations', function (Blueprint $table) {
            $table->dropIndex(['transaction_id']);
            $table->dropIndex(['business_shortcode']);
            $table->dropIndex(['mobile_number']);
            $table->dropIndex(['billref_no']);
            $table->dropIndex(['transaction_time']);

            $table->string('transaction_amount')->nullable()->change();
            $table->string('org_balance')->nullable()->change();
        });

        Schema::table('mpesa_stk_payments', function (Blueprint $table) {
            $table->dropIndex(['transaction_id']);
            $table->dropIndex(['msisdn']);
            $table->dropIndex(['business_shortcode']);
            $table->dropIndex(['created_at']);
        });
    }
};
