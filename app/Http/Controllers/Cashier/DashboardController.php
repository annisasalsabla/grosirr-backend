<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Product;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardController extends Controller
{
    use ApiResponseTrait;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Display Cashier Dashboard
     * GET /api/cashier/dashboard
     */
    public function index(Request $request)
    {
        try {
            $cashierId = $request->user()->id;
            $today = Carbon::today();

            // 1. Jumlah transaksi BERHASIL hari ini (semua status valid)
            $transactionCount = Transaction::where('cashier_id', $cashierId)
                ->whereDate('created_at', $today)
                ->validSales()
                ->count();

            // 2. Total uang tunai di laci (Fisik Laci Kasir) - TIDAK DISENTUH
            // a. Penjualan Tunai Reguler Lunas hari ini
            $cashReguler = Transaction::where('cashier_id', $cashierId)
                ->whereDate('created_at', $today)
                ->where('payment_method', 'cash')
                ->where('payment_status', 'paid')
                ->sum('total_amount');

            // b. Pelunasan / Cicilan / DP Piutang yang dibayar tunai hari ini oleh kasir ini
            $cashCicilan = \App\Models\ReceivablePayment::where('cashier_id', $cashierId)
                ->whereDate('payment_date', $today)
                ->where('payment_channel', 'CASH')
                ->sum('amount_paid');

            $cashInDrawer = $cashReguler + $cashCicilan;

            // 2b. Opsi 2a: Total amount HANYA transaksi paid hari ini (tetap murni lunas)
            $totalPaidToday = Transaction::where('cashier_id', $cashierId)
                ->whereDate('created_at', $today)
                ->where('payment_status', 'paid')
                ->sum('total_amount');

            // 2c. Opsi 2b: Total omzet kotor hari ini (semua transaksi valid)
            $totalOmzetToday = Transaction::where('cashier_id', $cashierId)
                ->whereDate('created_at', $today)
                ->validSales()
                ->sum('total_amount');

            // 3. Info stok tersedia
            $totalProducts = Product::count();
            $lowStockItems = Product::where('stock', '<=', 10)
                ->where('stock', '>', 0)
                ->select('id', 'name', 'stock')
                ->get();

            // 4. Transaksi terakhir hari ini (3 terakhir, semua status valid)
            $recentTransactions = Transaction::where('cashier_id', $cashierId)
                ->whereDate('created_at', $today)
                ->validSales()
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get(['id', 'invoice_number', 'total_amount', 'payment_method', 'payment_status', 'created_at']);

            $recentTransactions = $recentTransactions->map(function ($tx) {
                $statusLabel = 'Lunas';
                if ($tx->payment_method === 'receivable') {
                    $statusLabel = $tx->payment_status === 'paid' ? 'Lunas' : ($tx->payment_status === 'partial' ? 'DP / Sisa' : 'Piutang');
                } elseif ($tx->payment_method === 'midtrans_qris') {
                    $statusLabel = $tx->payment_status === 'paid' ? 'Lunas' : 'Pending';
                }
                
                $tx->status_label = $statusLabel;
                $tx->total_amount_formatted = 'Rp ' . number_format($tx->total_amount, 0, ',', '.');
                return $tx;
            });

            return $this->success([
                'summary' => [
                    'transaction_today' => $transactionCount,
                    'total_paid_today' => (float) $totalPaidToday,
                    'total_paid_formatted' => 'Rp ' . number_format($totalPaidToday, 0, ',', '.'),
                    'total_omzet_today' => (float) $totalOmzetToday,
                    'total_omzet_formatted' => 'Rp ' . number_format($totalOmzetToday, 0, ',', '.'),
                    'cash_in_drawer' => (float) $cashInDrawer,
                    'cash_formatted' => 'Rp ' . number_format($cashInDrawer, 0, ',', '.'),
                    'stock_info' => [
                        'total_items' => $totalProducts,
                        'low_stock_warning' => $lowStockItems->count(),
                        'low_stock_items' => $lowStockItems
                    ]
                ],
                'recent_transactions' => $recentTransactions,
                'cashier_name' => $request->user()->name
            ], 'Dashboard kasir berhasil dimuat');
        } catch (\Exception $e) {
            $this->logger->error('Cashier dashboard error: ' . $e->getMessage());
            return $this->error('Gagal memuat dashboard kasir');
        }
    }
}