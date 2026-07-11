<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Simulate adding columns
try {
    \Illuminate\Support\Facades\Schema::table('transactions', function ($table) {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'payment_fee_percentage')) {
            $table->decimal('payment_fee_percentage', 5, 2)->nullable();
        }
        if (!\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'payment_fee_amount')) {
            $table->decimal('payment_fee_amount', 15, 2)->nullable();
        }
    });
} catch (\Exception $e) {}

// Simulate backfill fee for July (just to have some test data, wait, I can just mock the fee for testing)
// Or I can calculate it in PHP memory for the test script.

$query = \App\Models\Transaction::whereBetween('tx_date', ['2026-07-01', '2026-07-31'])
    // HAPUS filter midtrans_qris!
    ->whereIn('payment_status', ['paid', 'partial', 'unpaid', 'pending']); // Owner logic normally includes pending, Admin excludes pending?
    
// Wait, the user said: "Filter payment_status (exclude 'pending' dari laporan resmi, sesuai aturan yang sudah disepakati sebelumnya) diterapkan SAMA di kedua sisi."
// Ah, previously we agreed that 'pending' should be excluded from "Omzet Rupiah", but included in "Total Transaksi"? Or excluded from both?
// Wait, in previous tasks: "Omzet Rupiah memang sudah aman (pending tidak dijumlahkan)."
// But the user says: "Filter payment_status (exclude 'pending' dari laporan resmi...) diterapkan SAMA di kedua sisi".

// Let's use the explicit shared logic.
$transactions = \App\Models\Transaction::whereBetween('tx_date', ['2026-07-01', '2026-07-31'])
    // EXCLUDE pending, failed, cancelled
    ->whereIn('payment_status', ['paid', 'partial', 'unpaid'])
    ->get();

$omzetKotor = 0;
$omzetBersih = 0;
$totalFee = 0;

foreach ($transactions as $tx) {
    $omzetKotor += $tx->total_amount;
    
    // Simulate snapshot logic
    $feePercentage = 0;
    if (in_array($tx->payment_method, ['qris', 'qris_statis', 'qris_biasa'])) {
        $feePercentage = 0.7; // From settings
    } elseif ($tx->payment_method === 'midtrans_qris') {
        $feePercentage = 1.5; // From settings
    }
    
    $feeAmount = $tx->total_amount * ($feePercentage / 100);
    $omzetBersih += ($tx->total_amount - $feeAmount);
    $totalFee += $feeAmount;
}

echo "=== HASIL TEST JULI 2026 ===\n";
echo "Total Transaksi: " . $transactions->count() . "\n";
echo "Omzet Kotor: Rp " . number_format($omzetKotor, 0, ',', '.') . "\n";
echo "Omzet Bersih: Rp " . number_format($omzetBersih, 0, ',', '.') . "\n";
echo "Total Potongan Fee: Rp " . number_format($totalFee, 0, ',', '.') . "\n";
