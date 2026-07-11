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
        Schema::table('bad_products', function (Blueprint $table) {
            $table->enum('status_kompensasi', ['belum_diganti', 'diganti_uang', 'diganti_barang'])->default('belum_diganti')->after('status');
            $table->date('tanggal_kompensasi')->nullable()->after('status_kompensasi');
            $table->text('catatan_kompensasi')->nullable()->after('tanggal_kompensasi');
            $table->decimal('jumlah_kompensasi_uang', 15, 2)->nullable()->after('catatan_kompensasi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bad_products', function (Blueprint $table) {
            $table->dropColumn([
                'status_kompensasi',
                'tanggal_kompensasi',
                'catatan_kompensasi',
                'jumlah_kompensasi_uang'
            ]);
        });
    }
};
