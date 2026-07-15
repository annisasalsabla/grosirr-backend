<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Payable;
use App\Models\User;
use App\Models\Stock;
use App\Models\BadProduct;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * Display Admin Dashboard
     * GET /api/admin/dashboard
     */
    public function index(Request $request)
    {
        try {
            // Use Asia/Jakarta timezone (WIB) consistent with StockController
            $today = Carbon::now('Asia/Jakarta');
            $todayStart = $today->copy()->startOfDay();
            $todayEnd = $today->copy()->endOfDay();

            // ========== 1. RINGKASAN DATA HARI INI ==========

            $todayDateStr = $today->toDateString(); // Y-m-d

            // Penjualan (Rp): Akumulasi total nilai transaksi hari ini
            $todaySales = Transaction::where('tx_date', $todayDateStr)
            ->validSales()
                ->sum('total_amount');

            // Transaksi: Hitung total kuantitas transaksi hari ini
            $todayTransactionCount = Transaction::where('tx_date', $todayDateStr)
            ->validSales()
                ->count();

            // Barang Rusak: Total kuantitas logs barang rusak hari ini
            $todayBadProducts = BadProduct::whereBetween('created_at', [$todayStart, $todayEnd])
                ->sum('quantity');

            // ========== 2. STATUS INVENTARIS REAL-TIME ==========

            // Total Produk: Hitung total variasi produk terdaftar aktif
            $totalProducts = Product::count();

            // Total Stok: Akumulasi seluruh jumlah stok dari semua produk
            $totalStock = Product::sum('stock');

            // Stok Masuk Hari Ini: Total kuantitas barang ditambahkan via form tambah stok
            $stockInToday = Stock::whereBetween('created_at', [$todayStart, $todayEnd])
                ->where('type', 'in')
                ->sum('quantity');

            // Stok Keluar Hari Ini: Total kuantitas barang berhasil dijual
            $stockOutToday = Stock::whereBetween('created_at', [$todayStart, $todayEnd])
                ->where('type', 'out')
                ->sum('quantity');

            // ========== 3. STOK HAMPIR HABIS (Berdasarkan min_stock per produk) ==========
            $lowStockItems = Product::whereColumn('stock', '<=', 'min_stock')
                ->where('stock', '>', 0)
                ->select('id', 'name', 'stock', 'min_stock', 'unit')
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'nama_produk' => $item->name,
                        'stok' => (int) $item->stock,
                        'stok_minimum' => (int) $item->min_stock,
                        'satuan' => $item->unit ?? 'pcs'
                    ];
                });

            // ========== 4. STATUS PIUTANG (RECEIVABLE) ==========
            $approachingDueReceivables = Receivable::where('status', '!=', 'paid')
                ->whereBetween('due_date', [$today, $today->copy()->addDays(5)])
                ->count();

            $overdueReceivables = Receivable::where('status', '!=', 'paid')
                ->where('due_date', '<', $today)
                ->count();

            $totalReceivableActive = Receivable::where('status', '!=', 'paid')
                ->sum('remaining_debt');

            // ========== 5. STATUS HUTANG SUPPLIER (PAYABLE) ==========
            $approachingDuePayables = Payable::where('status', '!=', 'paid')
                ->whereBetween('due_date', [$today, $today->copy()->addDays(5)])
                ->count();

            $overduePayables = Payable::where('status', '!=', 'paid')
                ->where('due_date', '<', $today)
                ->count();

            $totalPayableActive = Payable::where('status', '!=', 'paid')
                ->sum('remaining_debt');

            // ========== 6. KASIR AKTIF ==========
            $activeCashiers = User::where('role', 'cashier')
                ->where('is_active', true)
                ->count();

            // ========== 7. GRAFIK MINGGUAN (7 Hari Terakhir) - SINGLE QUERY ==========
            $dayNamesShort = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
            $sevenDaysAgoStr = $today->copy()->subDays(6)->toDateString();

            $weeklyRaw = Transaction::select(
                    'tx_date as date',
                    DB::raw('SUM(total_amount) as daily_sales')
                )
                ->whereBetween('tx_date', [$sevenDaysAgoStr, $todayDateStr])
                ->validSales()
                ->groupBy('tx_date')
                ->orderBy('tx_date')
                ->pluck('daily_sales', 'date')
                ->toArray();

            $weeklyData = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = $today->copy()->subDays($i)->format('Y-m-d');
                $weeklyData[] = [
                    'date' => $date,
                    'day_name' => $dayNamesShort[Carbon::parse($date)->dayOfWeek],
                    'sales' => (float) ($weeklyRaw[$date] ?? 0)
                ];
            }

            // Format chart data for response
            $chartLabels = array_column($weeklyData, 'day_name');
            $chartData = array_column($weeklyData, 'sales');

            // ========== 8. TRANSAKSI TERAKHIR (5 Terbaru - Semua Waktu) - PAID only ==========
            $lastTransactions = Transaction::query()
                ->whereIn('payment_status', ['paid', 'partial', 'unpaid', 'pending'])
                ->with(['customer', 'details.product'])
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(function ($tx) {
                    $statusLabel = 'Lunas';
                    if ($tx->payment_method === 'receivable') {
                        $statusLabel = $tx->payment_status === 'paid' ? 'Lunas' : ($tx->payment_status === 'partial' ? 'DP / Sisa' : 'Piutang');
                    } elseif ($tx->payment_method === 'midtrans_qris') {
                        $statusLabel = $tx->payment_status === 'paid' ? 'Lunas' : 'Pending';
                    }
                    
                    $items = $tx->details->map(function ($detail) {
                        return $detail->product ? $detail->product->name : 'Produk Tidak Dikenal';
                    })->toArray();
                    
                    return [
                        'id' => $tx->id,
                        'waktu' => $tx->created_at->format('H:i'),
                        'tanggal' => $tx->created_at->format('d M Y'),
                        'nama_pelanggan' => $tx->customer ? $tx->customer->name : ($tx->customer_name ?? 'Tidak ada'),
                        'total' => (float) $tx->total_amount,
                        'total_formatted' => 'Rp ' . number_format($tx->total_amount, 0, ',', '.'),
                        'metode_bayar' => $tx->payment_method ?? 'Tunai',
                        'status_label' => $statusLabel,
                        'items' => $items
                    ];
                });

            // ========== 9. PRODUK TERLARIS (Top 3 - 30 Hari Terakhir) - JOIN QUERY ==========
            // PAID only, last 30 days
            $thirtyDaysAgo = Carbon::now('Asia/Jakarta')->subDays(30);
            $bestSellingProducts = TransactionDetail::where('transaction_details.created_at', '>=', $thirtyDaysAgo)
                ->whereIn('transactions.payment_status', ['paid', 'partial', 'unpaid'])
                ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
                ->join('products', 'transaction_details.product_id', '=', 'products.id')
                ->select(
                    'transaction_details.product_id',
                    DB::raw('SUM(transaction_details.quantity) as total_terjual'),
                    DB::raw('SUM(transaction_details.price * transaction_details.quantity) as total_pendapatan'),
                    'products.name as product_name',
                    'products.unit as product_unit'
                )
                ->groupBy('transaction_details.product_id', 'products.name', 'products.unit')
                ->orderByDesc('total_terjual')
                ->limit(3)
                ->get()
                ->map(function ($item, $index) {
                    return [
                        'peringkat' => $index + 1,
                        'nama_produk' => $item->product_name ?: 'Produk #' . $item->product_id,
                        'total_terjual' => (int) $item->total_terjual,
                        'satuan' => $item->product_unit ?? 'pcs',
                        'total_pendapatan' => (float) $item->total_pendapatan,
                        'total_pendapatan_formatted' => 'Rp ' . number_format($item->total_pendapatan, 0, ',', '.')
                    ];
                });

            return $this->success([
                // ========== 1. SUMMARY ==========
                'summary' => [
                    'total_penjualan' => (float) $todaySales,
                    'total_penjualan_formatted' => 'Rp ' . number_format($todaySales, 0, ',', '.'),
                    'total_transaksi' => $todayTransactionCount,
                    'total_barang_rusak' => (int) $todayBadProducts
                ],

                // ========== 2. INVENTORY ==========
                'inventory' => [
                    'total_produk' => $totalProducts,
                    'total_stok' => (int) $totalStock,
                    'stok_masuk_hari_ini' => (int) $stockInToday,
                    'stok_keluar_hari_ini' => (int) $stockOutToday
                ],

                // ========== 3. PIUTANG ==========
                'piutang' => [
                    'total_piutang' => (float) $totalReceivableActive,
                    'total_piutang_formatted' => 'Rp ' . number_format($totalReceivableActive, 0, ',', '.'),
                    'jatuh_tempo' => $approachingDueReceivables + $overdueReceivables
                ],

                // ========== 4. HUTANG SUPPLIER (BARU) ==========
                'hutang_supplier' => [
                    'total_hutang' => (float) $totalPayableActive,
                    'total_hutang_formatted' => 'Rp ' . number_format($totalPayableActive, 0, ',', '.'),
                    'jatuh_tempo' => $approachingDuePayables + $overduePayables
                ],

                // ========== 5. CHART ==========
                'chart' => [
                    'labels' => $chartLabels,
                    'data' => $chartData
                ],

                // ========== 6. STOK HAMPIR HABIS ==========
                'stok_hampir_habis' => $lowStockItems,

                // ========== 7. TRANSAKSI TERAKHIR (BARU) ==========
                'transaksi_terakhir' => $lastTransactions,

                // ========== 8. PRODUK TERLARIS (BARU) ==========
                'produk_terlaris' => $bestSellingProducts
            ], 'Dashboard admin berhasil dimuat');
        } catch (\Exception $e) {
            $this->logger->error('Admin dashboard error: ' . $e->getMessage());
            return $this->error('Gagal memuat dashboard admin: ' . $e->getMessage());
        }
    }
}
