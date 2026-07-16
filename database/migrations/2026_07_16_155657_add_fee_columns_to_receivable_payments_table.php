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
        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->decimal('payment_fee_percentage', 5, 2)->default(0)->after('payment_date');
            $table->decimal('payment_fee_amount', 12, 2)->default(0)->after('payment_fee_percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->dropColumn(['payment_fee_percentage', 'payment_fee_amount']);
        });
    }
};
