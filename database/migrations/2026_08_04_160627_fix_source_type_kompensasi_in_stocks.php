<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Ambil semua baris yang terlanjur tercatat sebagai 'pembelian' 
        // padahal deskripsinya adalah kompensasi barang.
        $affectedRows = DB::table('stocks')
            ->where('source_type', 'pembelian')
            ->where('description', 'LIKE', '%Kompensasi barang dari supplier%')
            ->update([
                'source_type' => 'kompensasi_supplier'
            ]);
            
        // Catat ke log
        Log::info("Data Cleanup Migration: $affectedRows baris diperbaiki menjadi kompensasi_supplier");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No rollback needed for data cleanup
    }
};
