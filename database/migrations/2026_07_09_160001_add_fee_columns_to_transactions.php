<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Setting;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tambah baris di tabel settings
        Setting::setValue('qris_fee_percentage', '0.7', 'decimal');
        Setting::setValue('midtrans_fee_percentage', '1.5', 'decimal');

        // 2. Tambah kolom di tabel transactions
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'payment_fee_percentage')) {
                $table->decimal('payment_fee_percentage', 5, 2)->nullable()->after('change_due');
            }
            if (!Schema::hasColumn('transactions', 'payment_fee_amount')) {
                $table->decimal('payment_fee_amount', 15, 2)->nullable()->after('payment_fee_percentage');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['payment_fee_percentage', 'payment_fee_amount']);
        });
    }
};
