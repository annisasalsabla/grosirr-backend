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
            $table->timestamp('payment_date')->nullable()->after('payment_channel');
        });

        // Copy existing paid_at timestamps to payment_date
        \Illuminate\Support\Facades\DB::table('receivable_payments')->update([
            'payment_date' => \Illuminate\Support\Facades\DB::raw('paid_at')
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->dropColumn('payment_date');
        });
    }
};
