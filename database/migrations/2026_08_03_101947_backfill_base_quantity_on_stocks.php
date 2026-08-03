<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Backfill data lama yang terlanjur base_quantity=0 pada transaksi penjualan
        DB::statement("
            UPDATE stocks 
            SET base_quantity = quantity 
            WHERE base_quantity = 0 
            AND quantity > 0 
            AND description NOT LIKE 'Barang rusak (update)%'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible reliably without tracking previous values, so left empty
    }
};
