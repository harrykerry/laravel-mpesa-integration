<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A push that the customer cancels, or that times out, is an outcome
     * worth keeping: M-PESA reports it with a result code and description,
     * so a pending push can be told apart from a failed one.
     */
    public function up(): void
    {
        Schema::table('mpesa_stk_payments', function (Blueprint $table) {
            $table->integer('result_code')->nullable()->after('msisdn');
            $table->string('result_desc')->nullable()->after('result_code');
            $table->timestamp('completed_at')->nullable()->after('result_desc');

            $table->index('result_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mpesa_stk_payments', function (Blueprint $table) {
            $table->dropIndex(['result_code']);
            $table->dropColumn(['result_code', 'result_desc', 'completed_at']);
        });
    }
};
