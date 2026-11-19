<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Payouts, reversals, status queries and balance queries are answered
     * twice by M-PESA: an acknowledgement now, the outcome later on the
     * result callback. Each request is written here when it is accepted, so
     * the outcome has something to be matched to, and so money that left
     * this shortcode is on record.
     */
    public function up(): void
    {
        Schema::create('mpesa_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->string('conversation_id')->nullable()->index();
            $table->string('originator_conversation_id')->nullable()->index();
            $table->string('reference')->nullable()->index();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('status')->default('accepted')->index();
            $table->integer('result_code')->nullable();
            $table->string('result_desc')->nullable();
            $table->json('request')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesa_requests');
    }
};
