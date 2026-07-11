<?php

namespace App\Services;

use App\Models\Profit;
use App\Models\Transaction;

class ProfitCalculatorService
{
    /**
     * Hitung dan simpan profit untuk satu transaksi
     * Menggunakan snapshot price dan purchase_price dari transaction_details
     */
    public function calculateAndSaveProfit(Transaction $transaction): void
    {
        foreach ($transaction->details as $detail) {
            // Skip jika purchase_price null (data lama)
            if (is_null($detail->purchase_price)) {
                continue;
            }

            $profitAmount = $this->calculateProfitPerDetail($detail);

            $fromReceivable = $transaction->payment_method === 'receivable';
            $receivableStatus = $fromReceivable ? 'unpaid' : null;

            Profit::create([
                'transaction_id' => $transaction->id,
                'product_id' => $detail->product_id,
                'quantity_sold' => $detail->quantity,
                'profit_amount' => $profitAmount,
                'profit_date' => $transaction->created_at->toDateString(),
                'profit_date_only' => $transaction->created_at->toDateString(),
                'is_from_receivable' => $fromReceivable,
                'receivable_status' => $receivableStatus,
            ]);

        }
    }

    /**
     * Hitung profit per detail item
     * profit = (selling_price - purchase_price) × quantity
     */
    public function calculateProfitPerDetail($detail): float
    {
        $profitPerUnit = $detail->price - $detail->purchase_price;
        return $profitPerUnit * $detail->quantity;
    }

    /**
     * Get total profit untuk tanggal tertentu
     */
    public function getDailyProfit(string $date): float
    {
        return Profit::whereDate('profit_date', $date)->sum('profit_amount');
    }

    /**
     * Get profit harian by kategori
     */
    public function getDailyProfitByCategory(string $date, string $category): float
    {
        return Profit::whereDate('profit_date', $date)
            ->whereHas('product', fn($q) => $q->where('category', $category))
            ->sum('profit_amount');
    }

    /**
     * Get profit mingguan
     */
    public function getWeeklyProfit(): float
    {
        return Profit::whereBetween('profit_date', [now()->startOfWeek(), now()->endOfWeek()])->sum('profit_amount');
    }

    /**
     * Get profit bulanan
     */
    public function getMonthlyProfit(int $month, int $year): float
    {
        return Profit::whereMonth('profit_date', $month)->whereYear('profit_date', $year)->sum('profit_amount');
    }

    /**
     * Get total profit semua telur
     */
    public function getEggTotalProfit(): float
    {
        return Profit::whereHas('product', function ($query) {
            $query->where('category', 'egg');
        })->sum('profit_amount');
    }

    /**
     * Get total profit semua beras
     */
    public function getRiceTotalProfit(): float
    {
        return Profit::whereHas('product', function ($query) {
            $query->where('category', 'rice');
        })->sum('profit_amount');
    }

    /**
     * Get transaction detail from a profit record
     */
    public function getTransactionDetail($profit)
    {
        return $profit->transaction
            ? $profit->transaction->details->firstWhere('product_id', $profit->product_id)
            : null;
    }

    /**
     * Calculate total pendapatan kotor (omzet) from profits collection
     */
    public function calculateTotalOmzet($profits): float
    {
        return $profits->sum(function ($p) {
            $detail = $this->getTransactionDetail($p);
            return $detail ? (float) ($detail->price * $p->quantity_sold) : 0.0;
        });
    }

    /**
     * Calculate total beban (modal) from profits collection
     */
    public function calculateTotalBeban($profits): float
    {
        return $profits->sum(function ($p) {
            $detail = $this->getTransactionDetail($p);
            return $detail ? (float) ($detail->purchase_price * $p->quantity_sold) : 0.0;
        });
    }
}