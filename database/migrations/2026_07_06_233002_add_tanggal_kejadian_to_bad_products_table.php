<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bad_products', function (Blueprint $table) {
            $table->date('tanggal_kejadian')->nullable()->after('loss_amount');
        });

        // Sinkronisasi data historis
        DB::table('bad_products')->whereNull('tanggal_kejadian')->update([
            'tanggal_kejadian' => DB::raw('COALESCE(incident_date, created_at, NOW())')
        ]);

        Schema::table('bad_products', function (Blueprint $table) {
            $table->date('tanggal_kejadian')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('bad_products', function (Blueprint $table) {
            $table->dropColumn('tanggal_kejadian');
        });
    }
};
