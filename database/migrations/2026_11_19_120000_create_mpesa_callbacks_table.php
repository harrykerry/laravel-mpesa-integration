<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every callback M-PESA sends is written here before anything is done
     * with it: the acknowledgement goes back immediately, a queued job does
     * the work, and the raw payload stays for replay and audit. The
     * fingerprint makes M-PESA's retries land on the same row instead of
     * being processed twice.
     */
    public function up(): void
    {
        Schema::create('mpesa_callbacks', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->string('fingerprint', 64)->unique();
            $table->string('reference')->nullable()->index();
            $table->json('payload');
            $table->string('ip_address', 45)->nullable();
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });

        // A C2B receipt number identifies one payment, so the same receipt
        // can never be stored twice however often M-PESA resends it.
        Schema::table('mpesa_confirmations', function (Blueprint $table) {
            $table->dropIndex(['transaction_id']);
            $table->unique('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mpesa_confirmations', function (Blueprint $table) {
            $table->dropUnique(['transaction_id']);
            $table->index('transaction_id');
        });

        Schema::dropIfExists('mpesa_callbacks');
    }
};
