<?php
namespace App\Traits;

trait SalesReportCalculator
{
    /**
     * Hitung Omzet Kotor, Bersih, dan Total Fee 
     * dari query transaksi yang sudah difilter (tanggal/kategori).
     * 
     * Status Pembayaran yang valid (exclude 'pending', 'failed', 'cancelled'):
     * - 'paid', 'partial', 'unpaid'
     */
    protected function calculateSalesSummary($query, $categoryId = null)
    {
        // Terapkan filter status baku
        $validQuery = (clone $query)->whereIn('payment_status', ['paid', 'partial', 'unpaid']);

        if ($categoryId && $categoryId !== 'all') {
            // Omzet Kotor spesifik kategori
            $omzetKotor = (float) \App\Models\TransactionDetail::whereIn('transaction_id', (clone $validQuery)->pluck('id'))
                ->whereHas('product', function($q) use ($categoryId) {
                    $q->where('category', $categoryId);
                })
                ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));
                
            // Fee QRIS diset 0 karena sulit dibagi proporsional, Omzet Bersih = Omzet Kotor
            $totalFee = 0.0;
        } else {
            // Omzet Kotor Global
            $omzetKotor = (float) (clone $validQuery)->sum('total_amount');
            // Total Fee Global
            $totalFee = (float) (clone $validQuery)->sum('payment_fee_amount');
        }

        // Omzet Bersih: Omzet Kotor dikurangi Total Fee
        $omzetBersih = $omzetKotor - $totalFee;

        return [
            'total_omzet_kotor' => $omzetKotor,
            'total_omzet_kotor_formatted' => 'Rp ' . number_format($omzetKotor, 0, ',', '.'),
            'total_payment_fee' => $totalFee,
            'total_payment_fee_formatted' => 'Rp ' . number_format($totalFee, 0, ',', '.'),
            'total_omzet_bersih' => $omzetBersih,
            'total_omzet_bersih_formatted' => 'Rp ' . number_format($omzetBersih, 0, ',', '.'),
        ];
    }
}
