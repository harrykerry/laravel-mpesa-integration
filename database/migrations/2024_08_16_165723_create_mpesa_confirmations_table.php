<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mpesa_confirmations', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_type')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('transaction_time')->nullable();
            $table->string('transaction_amount')->nullable();
            $table->string('business_shortcode')->nullable();
            $table->string('billref_no')->nullable();
            $table->string('invoice_no')->nullable();
            $table->string('org_balance')->nullable();
            $table->string('thirdparty_transid')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesa_confirmations');
    }
};
