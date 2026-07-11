<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('bukti_pembayaran')->nullable()->after('change_due');
        });

        DB::statement("ALTER TABLE transactions MODIFY COLUMN payment_method ENUM('cash', 'transfer', 'qris', 'qris_statis', 'midtrans_qris', 'receivable') NOT NULL DEFAULT 'cash'");
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('bukti_pembayaran');
        });

        DB::statement("ALTER TABLE transactions MODIFY COLUMN payment_method ENUM('cash', 'transfer', 'qris', 'midtrans_qris', 'receivable') NOT NULL DEFAULT 'cash'");
    }
};
