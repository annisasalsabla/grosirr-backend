<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class TransactionObserver
{
    public function created(Transaction $transaction): void
    {
        if ($transaction->customer_id) {
            // Gunakan transaksi mandiri / bersarang (savepoint-safe di Laravel)
            DB::transaction(function () use ($transaction) {
                // Lock baris customer untuk mencegah race condition pembacaan status
                $customer = Customer::where('id', $transaction->customer_id)
                    ->lockForUpdate()
                    ->first();

                if ($customer && $customer->member_status === 'umum') {
                    // Hitung statistik transaksi real-time
                    $stats = DB::table('transactions')
                        ->where('customer_id', $customer->id)
                        ->selectRaw('COUNT(*) as total_transaksi, SUM(total_amount) as total_belanja')
                        ->first();

                    $totalTransaksi = (int) $stats->total_transaksi;
                    $totalBelanja = (float) ($stats->total_belanja ?? 0);

                    if ($totalTransaksi >= 5 || $totalBelanja >= 500000) {
                        $customer->update([
                            'member_status' => 'calon_member',
                            'calon_member_since' => now(),
                        ]);
                    }
                }
            });
        }
    }
}
