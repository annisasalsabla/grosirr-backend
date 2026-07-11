<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Kolom untuk Midtrans
            if (!Schema::hasColumn('transactions', 'midtrans_order_id')) {
                $table->string('midtrans_order_id')->nullable()->after('due_date');
            }
            
            if (!Schema::hasColumn('transactions', 'midtrans_snap_token')) {
                $table->string('midtrans_snap_token')->nullable()->after('midtrans_order_id');
            }
            
            if (!Schema::hasColumn('transactions', 'midtrans_qr_url')) {
                $table->string('midtrans_qr_url')->nullable()->after('midtrans_snap_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['midtrans_order_id', 'midtrans_snap_token', 'midtrans_qr_url']);
        });
    }
};