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
        Schema::create('mpesa_stk_payments', function (Blueprint $table) {
            $table->id();
            $table->string('merchant_request_id')->unique(); 
            $table->string('checkout_request_id')->unique(); 
            $table->string('transaction_id')->nullable();
            $table->string('transaction_date')->nullable();
            $table->string('business_shortcode')->nullable(); 
            $table->unsignedBigInteger('amount')->nullable(); 
            $table->string('msisdn')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesa_stk_payments');
    }
};
