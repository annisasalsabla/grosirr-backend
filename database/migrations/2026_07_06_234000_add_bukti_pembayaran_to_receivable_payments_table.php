<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Expand enum dulu — tambah nilai baru SAMBIL pertahankan QRIS_STATIC
        // agar MySQL tidak error saat data lama masih ada
        DB::statement("ALTER TABLE receivable_payments MODIFY COLUMN payment_channel ENUM('CASH', 'TRANSFER', 'QRIS_STATIC', 'QRIS_STATIS', 'QRIS_BIASA', 'MIDTRANS_QRIS') NOT NULL DEFAULT 'CASH'");

        // Step 2: Migrasi data lama QRIS_STATIC → QRIS_BIASA
        DB::table('receivable_payments')
            ->where('payment_channel', 'QRIS_STATIC')
            ->update(['payment_channel' => 'QRIS_BIASA']);

        // Step 3: Hapus QRIS_STATIC dari enum (sudah tidak ada data yang pakai)
        DB::statement("ALTER TABLE receivable_payments MODIFY COLUMN payment_channel ENUM('CASH', 'TRANSFER', 'QRIS_STATIS', 'QRIS_BIASA', 'MIDTRANS_QRIS') NOT NULL DEFAULT 'CASH'");

        // Step 4: Tambah kolom bukti_pembayaran (hanya jika belum ada)
        if (!Schema::hasColumn('receivable_payments', 'bukti_pembayaran')) {
            Schema::table('receivable_payments', function (Blueprint $table) {
                $table->string('bukti_pembayaran')->nullable()->after('paid_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->dropColumn('bukti_pembayaran');
        });

        // Restore ke versi sebelumnya (QRIS_STATIC + tanpa QRIS_BIASA)
        DB::statement("ALTER TABLE receivable_payments MODIFY COLUMN payment_channel ENUM('CASH', 'TRANSFER', 'QRIS_STATIC', 'MIDTRANS_QRIS') NOT NULL DEFAULT 'CASH'");
    }
};
