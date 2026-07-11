<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Update ENUM values without modifying the original migration
        Schema::table('receivable_payments', function (Blueprint $table) {
            DB::statement("
                ALTER TABLE receivable_payments
                MODIFY payment_channel
                ENUM('CASH', 'TRANSFER', 'QRIS_STATIC', 'MIDTRANS_QRIS')
                NOT NULL
            ");
        });
    }

    public function down(): void
    {
        Schema::table('receivable_payments', function (Blueprint $table) {
            DB::statement("
                ALTER TABLE receivable_payments
                MODIFY payment_channel
                ENUM('CASH', 'MIDTRANS_QRIS')
                NOT NULL
            ");
        });
    }
};

