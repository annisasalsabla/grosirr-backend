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
        // 1. Data Migration: SET NULL to 'belum_diganti'
        DB::table('bad_products')
            ->whereNull('status_kompensasi')
            ->update(['status_kompensasi' => 'belum_diganti']);

        // 2. Schema Migration: Change column to NOT NULL
        DB::statement("ALTER TABLE bad_products MODIFY COLUMN status_kompensasi ENUM('belum_diganti', 'diganti_sebagian', 'selesai') NOT NULL DEFAULT 'belum_diganti'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE bad_products MODIFY COLUMN status_kompensasi ENUM('belum_diganti', 'diganti_sebagian', 'selesai') NULL DEFAULT 'belum_diganti'");
    }
};
