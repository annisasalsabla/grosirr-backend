<?php
use Illuminate\Support\Facades\DB;

echo "========== CEK CUSTOMER 'tes' ==========\n";
$customers = DB::select("SELECT id, name, phone, member_status, is_ambiguous, created_at FROM customers WHERE name = 'tes' ORDER BY id ASC");

if (empty($customers)) {
    echo "Tidak ada customer bernama 'tes'.\n";
} else {
    foreach ($customers as $c) {
        echo "ID: {$c->id} | Name: {$c->name} | Phone: " . ($c->phone ?: 'NULL') . " | Status: {$c->member_status} | Ambiguous: {$c->is_ambiguous} | Created: {$c->created_at}\n";
        
        // Cek transaksi untuk tiap ID
        $stats = DB::select("SELECT COUNT(*) as count, SUM(total_amount) as total FROM transactions WHERE customer_id = ?", [$c->id]);
        $count = $stats[0]->count;
        $total = $stats[0]->total ?: 0;
        echo "   -> Transaksi: $count kali | Total Belanja: Rp " . number_format($total, 0, ',', '.') . "\n";
        
        // Detail transaksi
        $trx = DB::select("SELECT invoice_number, created_at FROM transactions WHERE customer_id = ? ORDER BY created_at DESC", [$c->id]);
        if (count($trx) > 0) {
            echo "   -> List Invoice:\n";
            foreach ($trx as $t) {
                echo "      - {$t->invoice_number} ({$t->created_at})\n";
            }
        }
        echo "----------------------------------------\n";
    }
}
