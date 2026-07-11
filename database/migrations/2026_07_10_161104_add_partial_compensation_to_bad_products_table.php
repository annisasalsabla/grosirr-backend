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
        Schema::table('bad_products', function (Blueprint $table) {
            $table->integer('compensated_quantity')->default(0)->after('quantity');
            $table->decimal('compensated_value', 15, 2)->default(0)->after('jumlah_kompensasi_uang');
        });

        // Update Enum menjadi netral
        DB::statement("ALTER TABLE bad_products MODIFY COLUMN status_kompensasi ENUM('belum_diganti', 'diganti_sebagian', 'selesai') DEFAULT 'belum_diganti'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE bad_products MODIFY COLUMN status_kompensasi ENUM('belum_diganti', 'diganti_uang', 'diganti_barang') DEFAULT 'belum_diganti'");
        
        Schema::table('bad_products', function (Blueprint $table) {
            $table->dropColumn(['compensated_quantity', 'compensated_value']);
        });
    }
};
