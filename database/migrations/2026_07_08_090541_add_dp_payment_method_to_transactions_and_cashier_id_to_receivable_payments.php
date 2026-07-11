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
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('dp_payment_method')->nullable()->after('payment_method');
        });

        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('cashier_id')->nullable()->after('transaction_id');
            $table->foreign('cashier_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->dropForeign(['cashier_id']);
            $table->dropColumn('cashier_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('dp_payment_method');
        });
    }
};
