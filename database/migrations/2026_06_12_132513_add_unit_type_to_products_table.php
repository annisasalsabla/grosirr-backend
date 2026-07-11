<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Menambahkan kolom unit_type untuk membedakan KARPET/Tray vs KARUNG
            // Nilai default 'tray' untuk telur, 'karung' untuk beras
            if (!Schema::hasColumn('products', 'unit_type')) {
                $table->enum('unit_type', ['tray', 'karung'])->default('tray')->after('unit');
            }
            
            // Menambahkan kolom price_per_unit (harga jual per satuan) jika belum ada
            // Sebenarnya sudah ada selling_price, jadi kita bisa menggunakan alias
            if (!Schema::hasColumn('products', 'price_per_unit')) {
                $table->decimal('price_per_unit', 15, 2)->nullable()->after('selling_price');
            }
        });
        
        // Update price_per_unit dengan nilai dari selling_price untuk data existing
        DB::statement('UPDATE products SET price_per_unit = selling_price WHERE price_per_unit IS NULL');
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['unit_type', 'price_per_unit']);
        });
    }
};