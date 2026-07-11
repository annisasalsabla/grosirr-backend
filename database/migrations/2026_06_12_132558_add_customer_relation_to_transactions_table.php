<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Jika kolom customer_id sudah ada, pastikan foreign key constraint
            // Jika belum ada, tambahkan
            if (!Schema::hasColumn('transactions', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('cashier_id');
            }
            
            // Tambahkan foreign key constraint jika belum ada
            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });
    }
};