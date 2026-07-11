<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // 1. Ambil data receivables yang berstatus lunas (paid) untuk disinkronkan ke transaksi dan profit
        $paidReceivables = DB::table('receivables')->where('status', 'paid')->get();
        foreach ($paidReceivables as $r) {
            // Update transaksi agar lunas
            DB::table('transactions')
                ->where('id', $r->transaction_id)
                ->update([
                    'payment_status' => 'paid',
                    'paid_amount' => $r->total_debt
                ]);

            // Update profit agar ditandai dari receivable dan lunas
            DB::table('profits')
                ->where('transaction_id', $r->transaction_id)
                ->update([
                    'is_from_receivable' => true,
                    'receivable_status' => 'paid'
                ]);
        }

        // 2. Ambil data receivables yang belum lunas (unpaid/partial) untuk disinkronkan
        $unpaidReceivables = DB::table('receivables')->whereIn('status', ['unpaid', 'partial'])->get();
        foreach ($unpaidReceivables as $r) {
            // Update transaksi agar statusnya sinkron
            DB::table('transactions')
                ->where('id', $r->transaction_id)
                ->update([
                    'payment_status' => $r->status,
                    'paid_amount' => $r->paid_amount
                ]);

            // Update profit agar ditandai dari receivable dan statusnya sinkron
            DB::table('profits')
                ->where('transaction_id', $r->transaction_id)
                ->update([
                    'is_from_receivable' => true,
                    'receivable_status' => $r->status
                ]);
        }
    }

    public function down()
    {
        // Kembalikan ke state awal sebelum migrasi untuk transaksi dan profit terkait (historis ID 65-74)
        $originalStates = [
            65 => ['payment_status' => 'failed', 'paid_amount' => 0.00, 'profit' => ['is_from_receivable' => false, 'receivable_status' => null]],
            66 => ['payment_status' => 'unpaid', 'paid_amount' => 0.00, 'profit' => ['is_from_receivable' => false, 'receivable_status' => null]],
            67 => ['payment_status' => 'failed', 'paid_amount' => 0.00, 'profit' => ['is_from_receivable' => false, 'receivable_status' => null]],
            68 => ['payment_status' => 'unpaid', 'paid_amount' => 0.00, 'profit' => ['is_from_receivable' => false, 'receivable_status' => null]],
            69 => ['payment_status' => 'failed', 'paid_amount' => 0.00, 'profit' => ['is_from_receivable' => false, 'receivable_status' => null]],
            70 => ['payment_status' => 'unpaid', 'paid_amount' => 0.00, 'profit' => ['is_from_receivable' => true, 'receivable_status' => 'unpaid']],
            71 => ['payment_status' => 'unpaid', 'paid_amount' => 0.00, 'profit' => ['is_from_receivable' => true, 'receivable_status' => 'unpaid']],
            72 => ['payment_status' => 'unpaid', 'paid_amount' => 0.00, 'profit' => ['is_from_receivable' => true, 'receivable_status' => 'unpaid']],
            73 => ['payment_status' => 'unpaid', 'paid_amount' => 0.00, 'profit' => ['is_from_receivable' => true, 'receivable_status' => 'unpaid']],
            74 => ['payment_status' => 'unpaid', 'paid_amount' => 0.00, 'profit' => ['is_from_receivable' => true, 'receivable_status' => 'paid']],
        ];

        foreach ($originalStates as $txId => $state) {
            DB::table('transactions')
                ->where('id', $txId)
                ->update([
                    'payment_status' => $state['payment_status'],
                    'paid_amount' => $state['paid_amount']
                ]);

            DB::table('profits')
                ->where('transaction_id', $txId)
                ->update([
                    'is_from_receivable' => $state['profit']['is_from_receivable'],
                    'receivable_status' => $state['profit']['receivable_status']
                ]);
        }
    }
};
