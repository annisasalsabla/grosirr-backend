<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\Receivable;
use App\Models\Profit;
use App\Models\Stock;
use App\Models\TransactionDetail;
use App\Traits\ApiResponseTrait;
use App\Services\ProfitCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    use ApiResponseTrait;

    protected $profitService;

    // Potongan fee QRIS (%): QRIS statis 0.5%, QRIS Midtrans 0.7%
    private const QRIS_FEE_PERCENT = 0.5;
    private const MIDTRANS_QRIS_FEE_PERCENT = 0.7;

    public function __construct(ProfitCalculatorService $profitService)
    {
        $this->profitService = $profitService;
    }

    /**
     * OPTIMIZED: Hitung fee QRIS untuk multiple dates dalam 1 query (menggunakan index column)
     */
    private function calculateQrisFeesBulk(array $dates): array
    {
        $fees = array_fill_keys($dates, 0);

        if (empty($dates)) {
            return $fees;
        }

        // Menggunakan tx_date column yang sudah di-index
        // PAID, PARTIAL, UNPAID, PENDING
        $results = Transaction::select(
            'tx_date',
            DB::raw("SUM(CASE WHEN payment_method = 'qris' THEN total_amount ELSE 0 END) as qris_total"),
            DB::raw("SUM(CASE WHEN payment_method = 'midtrans_qris' THEN total_amount ELSE 0 END) as midtrans_qris_total")
        )
            ->whereIn('tx_date', $dates)
            
            ->whereIn('payment_status', ['paid', 'partial', 'unpaid', 'pending'])
            ->groupBy('tx_date')
            ->get()
            ->keyBy('tx_date');

        foreach ($dates as $date) {
            if (isset($results[$date])) {
                $qrisTotal = (float) $results[$date]->qris_total;
                $midtransTotal = (float) $results[$date]->midtrans_qris_total;
                $fees[$date] = ($qrisTotal * (self::QRIS_FEE_PERCENT / 100))
                    + ($midtransTotal * (self::MIDTRANS_QRIS_FEE_PERCENT / 100));
            }
        }

        return $fees;
    }

    /**
     * OPTIMIZED: Get daily sales + profit by category dalam 1 query (menggunakan indexed columns)
     */
    private function getDailySalesByCategory(string $date): array
    {
        // Single query dengan JOIN langsung - pakai tx_date index
        $result = TransactionDetail::select('products.category')
            ->join('products', 'transaction_details.product_id', '=', 'products.id')
            ->join('transactions', 'transaction_details.transaction_id', '=', 'transactions.id')
            ->where('transactions.tx_date', $date)
            ->whereIn('transactions.payment_status', ['paid', 'partial', 'unpaid', 'pending'])
            ->groupBy('products.category')
            ->selectRaw('products.category, SUM(transactions.total_amount) as total_sales')
            ->get()
            ->keyBy('category');

        return [
            'egg' => (float) ($result['egg']->total_sales ?? 0),
            'rice' => (float) ($result['rice']->total_sales ?? 0),
        ];
    }

    /**
     * OPTIMIZED: Get daily profit by category dalam 1 query (menggunakan indexed column)
     */
    private function getDailyProfitByCategory(string $date): array
    {
        $validTransactionIds = Transaction::where('tx_date', $date)
            
            ->whereIn('payment_status', ['paid', 'partial', 'unpaid', 'pending'])
            ->pluck('id');

        $result = Profit::select('products.category')
            ->join('products', 'profits.product_id', '=', 'products.id')
            ->whereIn('profits.transaction_id', $validTransactionIds)
            ->groupBy('products.category')
            ->selectRaw('products.category, SUM(profit_amount) as total_profit')
            ->get()
            ->keyBy('category');

        return [
            'egg' => (float) ($result['egg']->total_profit ?? 0),
            'rice' => (float) ($result['rice']->total_profit ?? 0),
        ];
    }

    /**
     * OPTIMIZED: Get weekly sales + profit dalam 1 query per table (menggunakan indexed columns)
     */
    private function getWeeklyData(array $dates): array
    {
        $datesStr = array_map(fn($d) => $d->format('Y-m-d'), $dates);

        $validTransactions = Transaction::select('id', 'tx_date', 'total_amount')
            ->whereIn('tx_date', $datesStr)
            
            ->whereIn('payment_status', ['paid', 'partial', 'unpaid', 'pending'])
            ->get();
        $validTransactionIds = $validTransactions->pluck('id');

        $weeklySalesData = $validTransactions->groupBy('tx_date')->map(function ($group) {
            return (object) ['total' => $group->sum('total_amount')];
        });

        $weeklyProfitData = Profit::select(
            'profit_date_only',
            DB::raw('SUM(profit_amount) as total')
        )
            ->whereIn('transaction_id', $validTransactionIds)
            ->groupBy('profit_date_only')
            ->get()
            ->keyBy('profit_date_only');

        // Weekly QRIS fees - single query
        $weeklyFees = $this->calculateQrisFeesBulk($datesStr);

        return [
            'sales' => $weeklySalesData,
            'profit' => $weeklyProfitData,
            'fees' => $weeklyFees,
        ];
    }

    /**
     * Get stock in/out hari ini per kategori menggunakan scope reusable
     */
    private function getDailyStockMovements(): array
    {
        return [
            'egg' => [
                'name' => 'Telur',
                'stock_in' => (int) Stock::todayIn('egg')->sum('quantity'),
                'stock_out' => (int) Stock::todayOut('egg')->sum('quantity'),
            ],
            'rice' => [
                'name' => 'Beras',
                'stock_in' => (int) Stock::todayIn('rice')->sum('quantity'),
                'stock_out' => (int) Stock::todayOut('rice')->sum('quantity'),
            ],
        ];
    }

    /**
     * OPTIMIZED: Get total stock by category dalam 1 query
     */
    private function getTotalStockByCategory(): array
    {
        $result = Product::select('category')
            ->groupBy('category')
            ->selectRaw('category, SUM(stock) as total')
            ->get()
            ->keyBy('category');

        return [
            'egg' => (int) ($result['egg']->total ?? 0),
            'rice' => (int) ($result['rice']->total ?? 0),
        ];
    }

    /**
     * OPTIMIZED: Get total profit by category (semua waktu) dalam 1 query
     */
    private function getTotalProfitByCategory(): array
    {
        $validTransactionIds = Transaction::query()
            ->whereIn('payment_status', ['paid', 'partial', 'unpaid', 'pending'])
            ->pluck('id');
        $result = Profit::select('products.category')
            ->join('products', 'profits.product_id', '=', 'products.id')
            ->whereIn('profits.transaction_id', $validTransactionIds)
            ->groupBy('products.category')
            ->selectRaw('products.category, SUM(profit_amount) as total')
            ->get()
            ->keyBy('category');

        return [
            'egg' => (float) ($result['egg']->total ?? 0),
            'rice' => (float) ($result['rice']->total ?? 0),
        ];
    }

    public function index(Request $request)
    {
        try {
            // Use Asia/Jakarta timezone (WIB) consistent with AdminDashboard
            $today = now('Asia/Jakarta')->toDateString(); // String for tx_date/profit_date_only columns
            $todayStart = now('Asia/Jakarta')->copy()->startOfDay(); // Carbon for created_at filtering
            $todayEnd = now('Asia/Jakarta')->copy()->endOfDay();

            // Generate 7 hari terakhir untuk weekly chart (Urutan: 6 hari lalu -> Hari ini)
            $weekDates = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = $todayStart->copy()->subDays($i);
                $weekDates[] = $date;
            }

            // ========== SINGLE QUERY OPTIMIZATION ==========

            // 1. 4 Card Utama - semua dalam 1 query (menggunakan tx_date index, PAID, PENDING, UNPAID, PARTIAL)
            $summary = Transaction::select(
                DB::raw('SUM(total_amount) as daily_sales'),
                DB::raw('COUNT(*) as total_transactions')
            )
                ->where('tx_date', $today)
                
                ->whereIn('payment_status', ['paid', 'partial', 'unpaid', 'pending'])
                ->first();

            // QRIS fee hari ini - dari cache yang sudah di-prefetch
            $dailyQrisFeeArray = $this->calculateQrisFeesBulk([$today]);
            $dailyQrisFee = $dailyQrisFeeArray[$today] ?? 0;

            // 2. Piutang aktif - satu query
            $activeReceivables = Receivable::where('status', '!=', 'paid')
                ->sum('remaining_debt');

            // 3. Daily profit murni dari ID valid (Bebas dari failed/cancelled)
            $validDailyTxIds = Transaction::where('tx_date', $today)
                
                ->whereIn('payment_status', ['paid', 'partial', 'unpaid', 'pending'])
                ->pluck('id');
            $dailyProfitRaw = Profit::whereIn('transaction_id', $validDailyTxIds)
                ->sum('profit_amount');
            $dailyProfit = $dailyProfitRaw - $dailyQrisFee;

            // 4. Daily sales/profit by category - 2 query
            $dailySalesByCategory = $this->getDailySalesByCategory($today);
            $dailyProfitByCategory = $this->getDailyProfitByCategory($today);

            // 5. Weekly data - 3 query semuanya
            $weeklyData = $this->getWeeklyData($weekDates);

            // 6. Total stock by category - 1 query
            $totalStockByCategory = $this->getTotalStockByCategory();

            // 7. Total profit by category - 1 query
            $totalProfitByCategory = $this->getTotalProfitByCategory();

            // 8. Daily stock movements - menggunakan scope reusable (konsisten dengan AdminDashboard)
            $dailyStockMovements = $this->getDailyStockMovements();

            // ========== BUILD WEEK CHART ==========
            $weekDays = [];
            foreach ($weekDates as $date) {
                $dateStr = $date->format('Y-m-d');
                $weekDays[$dateStr] = [
                    'day' => $date->translatedFormat('l'),
                    'sales' => isset($weeklyData['sales'][$dateStr])
                        ? (int) $weeklyData['sales'][$dateStr]->total
                        : 0,
                    'profit' => 0, // akan dihitung di bawah
                ];
            }

            // Merge profit + hitung net profit dengan fee
            foreach ($weekDays as $dateStr => &$dayData) {
                if (isset($weeklyData['profit'][$dateStr])) {
                    $grossProfit = (float) $weeklyData['profit'][$dateStr]->total;
                    $fee = $weeklyData['fees'][$dateStr] ?? 0;
                    $dayData['profit'] = (int) ($grossProfit - $fee);
                }
            }
            unset($dayData);

            $chartData = [
                'labels' => array_values(array_map(fn($d) => $d['day'], $weekDays)),
                'sales' => array_values(array_map(fn($d) => $d['sales'], $weekDays)),
                'profit' => array_values(array_map(fn($d) => $d['profit'], $weekDays)),
            ];

            // ========== RESPONSE ==========
            $dashboard = [
                'summary' => [
                    'daily_sales' => (int) ($summary->daily_sales ?? 0),
                    'daily_sales_formatted' => 'Rp ' . number_format($summary->daily_sales ?? 0, 0, ',', '.'),
                    'daily_profit' => (int) $dailyProfit,
                    'daily_profit_formatted' => 'Rp ' . number_format($dailyProfit, 0, ',', '.'),
                    'active_receivables' => (int) $activeReceivables,
                    'active_receivables_formatted' => 'Rp ' . number_format($activeReceivables, 0, ',', '.'),
                    'total_transactions' => (int) ($summary->total_transactions ?? 0),
                    'total_transactions_formatted' => ($summary->total_transactions ?? 0) . ' Trx',
                ],
                'chart' => [
                    'labels' => $chartData['labels'],
                    'sales' => $chartData['sales'],
                    'profit' => $chartData['profit'],
                ],
                'profit_by_product' => [
                    'egg' => [
                        'name' => 'Telur',
                        'amount' => (int) $totalProfitByCategory['egg'],
                        'amount_formatted' => 'Rp ' . number_format($totalProfitByCategory['egg'], 0, ',', '.'),
                    ],
                    'rice' => [
                        'name' => 'Beras',
                        'amount' => (int) $totalProfitByCategory['rice'],
                        'amount_formatted' => 'Rp ' . number_format($totalProfitByCategory['rice'], 0, ',', '.'),
                    ],
                ],
                'daily_sales_by_category' => [
                    'egg' => [
                        'name' => 'Telur',
                        'amount' => (int) $dailySalesByCategory['egg'],
                        'amount_formatted' => 'Rp ' . number_format($dailySalesByCategory['egg'], 0, ',', '.'),
                    ],
                    'rice' => [
                        'name' => 'Beras',
                        'amount' => (int) $dailySalesByCategory['rice'],
                        'amount_formatted' => 'Rp ' . number_format($dailySalesByCategory['rice'], 0, ',', '.'),
                    ],
                ],
                'daily_profit_by_category' => [
                    'egg' => [
                        'name' => 'Telur',
                        'amount' => (int) $dailyProfitByCategory['egg'],
                        'amount_formatted' => 'Rp ' . number_format($dailyProfitByCategory['egg'], 0, ',', '.'),
                    ],
                    'rice' => [
                        'name' => 'Beras',
                        'amount' => (int) $dailyProfitByCategory['rice'],
                        'amount_formatted' => 'Rp ' . number_format($dailyProfitByCategory['rice'], 0, ',', '.'),
                    ],
                ],
                'total_stock_by_category' => [
                    'egg' => [
                        'name' => 'Telur',
                        'quantity' => $totalStockByCategory['egg'],
                    ],
                    'rice' => [
                        'name' => 'Beras',
                        'quantity' => $totalStockByCategory['rice'],
                    ],
                ],
                'qris_fee' => [
                    'total_fee' => (int) $dailyQrisFee,
                    'total_fee_formatted' => 'Rp ' . number_format($dailyQrisFee, 0, ',', '.'),
                ],
                'stock_summary' => $dailyStockMovements,
            ];

            return $this->success($dashboard, 'Data dashboard berhasil dimuat', 200);

        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan saat memuat dashboard: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Top 5 Produk Terlaris
     * GET /api/owner/dashboard/bestseller
     */
    public function bestseller(Request $request)
    {
        try {
            $request->validate([
                'period' => 'required|in:daily,weekly,monthly',
                'date' => 'nullable|date',
                'week' => 'nullable|integer|min:1|max:6',
                'month' => 'nullable|integer|min:1|max:12',
                'year' => 'nullable|integer|min:2020|max:2030',
            ]);

            $period = $request->input('period');
            $startDate = null;
            $endDate = null;

            // 1 & 2. Penentuan Filter Tanggal (tx_date) Identik SalesController Owner (Termasuk Clipping max:6)
            if ($period === 'daily') {
                $date = $request->input('date', now()->toDateString());
                $startDate = $date;
                $endDate = $date;
            } elseif ($period === 'weekly') {
                $month = $request->input('month', now()->month);
                $year = $request->input('year', now()->year);
                
                $firstOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1);
                $startOfMonth = $firstOfMonth->copy()->startOfMonth();
                $endOfMonth = $firstOfMonth->copy()->endOfMonth();
                
                $startOfWeekOne = $firstOfMonth->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
                $weekOffset = ($request->input('week', 1) - 1) * 7;
                
                $calcStart = $startOfWeekOne->copy()->addDays($weekOffset);
                $calcEnd = $startOfWeekOne->copy()->addDays($weekOffset + 6);
                
                $startDate = $calcStart->copy()->max($startOfMonth)->toDateString();
                $endDate = $calcEnd->copy()->min($endOfMonth)->toDateString();
            } elseif ($period === 'monthly') {
                $month = $request->input('month', now()->month);
                $year = $request->input('year', now()->year);
                
                $startDate = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
                $endDate = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();
            }

            // Filter Validasi Status (Exclude pending, identik SalesController)
            $validTransactionIds = Transaction::whereBetween('tx_date', [$startDate, $endDate])
                
                ->whereIn('payment_status', ['paid', 'partial', 'unpaid'])
                ->pluck('id');

            // 5. Handling murni jika tidak ada transaksi (Kembalikan Array Kosong, bukan null/error)
            if ($validTransactionIds->isEmpty()) {
                return $this->success([
                    'period' => $period,
                    'date_range' => ['start' => $startDate, 'end' => $endDate],
                    'top_products' => [],
                    'category_summary' => [
                        'egg' => ['total_revenue' => 0, 'total_revenue_formatted' => 'Rp 0', 'total_qty' => 0],
                        'rice' => ['total_revenue' => 0, 'total_revenue_formatted' => 'Rp 0', 'total_qty' => 0]
                    ]
                ], 'Data produk terlaris berhasil dimuat (Kosong)', 200);
            }

            // 3. Query Top 5 Produk (JOIN transactions_details & products, GROUP BY product_id)
            $topProducts = TransactionDetail::select(
                    'products.id as product_id',
                    'products.name as product_name',
                    'products.category as category',
                    DB::raw('SUM(transaction_details.subtotal) as total_revenue'),
                    DB::raw('SUM(transaction_details.quantity) as total_qty')
                )
                ->join('products', 'transaction_details.product_id', '=', 'products.id')
                ->whereIn('transaction_details.transaction_id', $validTransactionIds)
                ->groupBy('products.id', 'products.name', 'products.category')
                ->orderBy('total_revenue', 'desc')
                ->limit(5)
                ->get();

            // 4a. Response format top_products
            $formattedTop = $topProducts->map(function($item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'category' => $item->category,
                    'total_revenue' => (float) $item->total_revenue,
                    'total_revenue_formatted' => 'Rp ' . number_format($item->total_revenue, 0, ',', '.'),
                    'total_qty' => (int) $item->total_qty
                ];
            });

            // 4b. Query & Response Category Summary (Egg vs Rice keseluruhan)
            $categorySummaryRaw = TransactionDetail::select(
                    'products.category',
                    DB::raw('SUM(transaction_details.subtotal) as total_revenue'),
                    DB::raw('SUM(transaction_details.quantity) as total_qty')
                )
                ->join('products', 'transaction_details.product_id', '=', 'products.id')
                ->whereIn('transaction_details.transaction_id', $validTransactionIds)
                ->groupBy('products.category')
                ->get()
                ->keyBy('category');

            $eggRev = (float) ($categorySummaryRaw['egg']->total_revenue ?? 0);
            $eggQty = (int) ($categorySummaryRaw['egg']->total_qty ?? 0);
            $riceRev = (float) ($categorySummaryRaw['rice']->total_revenue ?? 0);
            $riceQty = (int) ($categorySummaryRaw['rice']->total_qty ?? 0);

            return $this->success([
                'period' => $period,
                'date_range' => ['start' => $startDate, 'end' => $endDate],
                'top_products' => $formattedTop,
                'category_summary' => [
                    'egg' => [
                        'total_revenue' => $eggRev,
                        'total_revenue_formatted' => 'Rp ' . number_format($eggRev, 0, ',', '.'),
                        'total_qty' => $eggQty
                    ],
                    'rice' => [
                        'total_revenue' => $riceRev,
                        'total_revenue_formatted' => 'Rp ' . number_format($riceRev, 0, ',', '.'),
                        'total_qty' => $riceQty
                    ]
                ]
            ], 'Data produk terlaris berhasil dimuat', 200);

        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan: ' . $e->getMessage(), null, 500);
        }
    }


}
