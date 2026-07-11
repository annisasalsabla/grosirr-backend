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
        // 1. Ubah tipe data `type` dan tambahkan `status`
        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->string('type')->change();
            $table->string('status')->default('active')->after('is_read');
        });

        // 2. Migrasi data lama
        $notifications = DB::table('admin_notifications')->get();

        foreach ($notifications as $notif) {
            $newType = $notif->type;
            $newStatus = 'active';

            if ($notif->type === 'debt_due') {
                $newType = 'hutang_jatuh_tempo';
                // Cek payable
                $payable = DB::table('payables')->where('id', $notif->reference_id)->first();
                if (!$payable || $payable->status === 'paid' || $payable->remaining_debt <= 0) {
                    $newStatus = 'resolved';
                }
            } elseif ($notif->type === 'receivable_due') {
                $newType = 'piutang_jatuh_tempo';
                // Cek receivable
                $receivable = DB::table('receivables')->where('id', $notif->reference_id)->first();
                if (!$receivable || $receivable->status === 'paid' || $receivable->remaining_debt <= 0) {
                    $newStatus = 'resolved';
                }
            } elseif ($notif->type === 'low_stock') {
                // Cek stok produk
                $product = DB::table('products')->where('id', $notif->reference_id)->first();
                if (!$product) {
                     $newType = 'stok_habis';
                     $newStatus = 'resolved';
                } else {
                    if ($product->stock <= 0) {
                        $newType = 'stok_habis';
                        if ($product->min_stock < 0) {
                            $newStatus = 'resolved';
                        }
                    } elseif ($product->stock <= $product->min_stock) {
                        $newType = 'stok_menipis';
                    } else {
                        $newType = 'stok_menipis';
                        $newStatus = 'resolved';
                    }
                }
            }

            DB::table('admin_notifications')->where('id', $notif->id)->update([
                'type' => $newType,
                'status' => $newStatus,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        $notifications = DB::table('admin_notifications')->get();
        foreach ($notifications as $notif) {
            $oldType = 'low_stock'; // default
            if ($notif->type === 'hutang_jatuh_tempo') {
                $oldType = 'debt_due';
            } elseif ($notif->type === 'piutang_jatuh_tempo') {
                $oldType = 'receivable_due';
            }
            DB::table('admin_notifications')->where('id', $notif->id)->update([
                'type' => $oldType
            ]);
        }

        DB::statement("ALTER TABLE admin_notifications MODIFY type ENUM('debt_due', 'receivable_due', 'low_stock') NOT NULL");
    }
};
