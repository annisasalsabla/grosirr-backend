<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfitController extends Controller
{
    use ApiResponseTrait, \App\Traits\DateRangeHelper;

    private const QRIS_FEE_PERCENT = 0.5;
    private const MIDTRANS_QRIS_FEE_PERCENT = 0.7;

    /**
     * Helper baru: Menyusun array Laba per kategori tanpa Query DB tambahan.
     * Menggunakan rumus absolut: Cost = Sales - ProfitRaw
     */
    private function formatProfitByCategory($profits, $totalSalesEgg, $totalSalesRice, $totalSalesAll)
    {
        $eggProfitRaw = $profits->filter(function($p) { 
            return $p->product && $p->product->category == 'egg'; 
        })->sum('profit_amount');
        
        $riceProfitRaw = $profits->filter(function($p) { 
            return $p->product && $p->product->category == 'rice'; 
        })->sum('profit_amount');
        
        $allProfitRaw = $profits->sum('profit_amount');

        $eggCost = $totalSalesEgg - $eggProfitRaw;
        $riceCost = $totalSalesRice - $riceProfitRaw;
        $allCost = $totalSalesAll - $allProfitRaw;

        return [
            'all' => [
                'name' => 'Semua Produk',
                'sales' => (int) $totalSalesAll,
                'cost' => (int) $allCost,
                'profit' => (int) $allProfitRaw,
            ],
            'egg' => [
                'name' => 'Telur',
                'sales' => (int) $totalSalesEgg,
                'cost' => (int) $eggCost,
                'profit' => (int) $eggProfitRaw,
            ],
            'rice' => [
                'name' => 'Beras',
                'sales' => (int) $totalSalesRice,
                'cost' => (int) $riceCost,
                'profit' => (int) $riceProfitRaw,
            ],
        ];
    }

    /**
     * Penjualan Harian
     * GET /api/owner/profit/daily
     */
    public function daily(Request $request)
    {
        try {
            $today = now()->toDateString();
            $date = $request->input('date', $today);

            $transactions = \App\Models\Transaction::where('tx_date', $date)
                // Catatan: Menu Laba MENGECUALIKAN 'pending' agar klop 100% dengan Menu Penjualan (sebagai Laporan Histori Resmi).
                // Berbeda dengan Dashboard yang menyertakan 'pending' untuk pemantauan real-time.
                ->where(function ($q) {
                    $q->where('payment_status', 'paid')
                      ->orWhere(function ($q2) {
                          $q2->where('payment_method', 'receivable')
                             ->whereIn('payment_status', ['unpaid', 'partial']);
                      });
                })
                ->get();
            $transactionIds = $transactions->pluck('id');

            $totalSales = $transactions->sum('total_amount');
            $qrisTotal = $transactions->where('payment_method', 'qris')->sum('total_amount');
            $midtransTotal = $transactions->where('payment_method', 'midtrans_qris')->sum('total_amount');
            $qrisFee = ($qrisTotal * (self::QRIS_FEE_PERCENT / 100)) + ($midtransTotal * (self::MIDTRANS_QRIS_FEE_PERCENT / 100));

            $profits = \App\Models\Profit::with('product')
                ->whereIn('transaction_id', $transactionIds)
                ->get();
            $totalProfitRaw = $profits->sum('profit_amount');

            $netProfit = $totalProfitRaw - $qrisFee;
            $totalCost = $totalSales - $totalProfitRaw;

            $totalSalesEgg = \App\Models\TransactionDetail::whereIn('transaction_id', $transactionIds)
                ->whereHas('product', fn($q) => $q->where('category', 'egg'))
                ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));

            $totalSalesRice = \App\Models\TransactionDetail::whereIn('transaction_id', $transactionIds)
                ->whereHas('product', fn($q) => $q->where('category', 'rice'))
                ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));

            $formattedDate = \Carbon\Carbon::parse($date)->format('d/m/Y');

            return $this->success([
                'period' => 'daily',
                'title' => 'Laba Harian - ' . $formattedDate,
                'date' => $date,
                'cards' => [
                    'total_sales' => [
                        'name' => 'Total Penjualan',
                        'amount' => (int) $totalSales,
                        'amount_formatted' => 'Rp ' . number_format($totalSales, 0, ',', '.'),
                    ],
                    'total_cost' => [
                        'name' => 'Total Modal (HPP)',
                        'amount' => (int) $totalCost,
                        'amount_formatted' => 'Rp ' . number_format($totalCost, 0, ',', '.'),
                    ],
                    'net_profit' => [
                        'name' => 'Laba Bersih',
                        'amount' => (int) $netProfit,
                        'amount_formatted' => 'Rp ' . number_format($netProfit, 0, ',', '.'),
                    ],
                ],
                'profit_by_category' => $this->formatProfitByCategory($profits, $totalSalesEgg, $totalSalesRice, $totalSales),
                'summary' => [
                    'total_sales' => (int) $totalSales,
                    'total_cost' => (int) $totalCost,
                    'qris_fee' => (int) $qrisFee,
                    'net_profit' => (int) $netProfit,
                ],
            ], 'Data laba harian berhasil dimuat', 200);

        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Penjualan Mingguan
     * GET /api/owner/profit/weekly
     */
    public function weekly(Request $request)
    {
        try {
            // --- NEW: Parameter Mapping ---
            if ($request->has('product_category')) {
                $cat = $request->product_category;
                if ($cat === 'telur') $request->merge(['category' => 'egg']);
                elseif ($cat === 'beras') $request->merge(['category' => 'rice']);
                elseif ($cat === 'all') $request->merge(['category' => 'all']);
            }
            // ------------------------------

            $request->validate([
                'week' => 'nullable|integer|min:1|max:6',
                'month' => 'nullable|integer|min:1|max:12',
                'year' => 'nullable|integer|min:2020|max:2030',
                'start' => 'nullable|date',
                'end' => 'nullable|date|after_or_equal:start',
                'category' => 'nullable|string|in:all,egg,rice',
            ]);

            if ($request->has('start') && $request->has('end')) {
                $startDate = $request->input('start');
                $endDate = $request->input('end');
            } else {
                $weekNum = $request->input('week', 1);
                $month = $request->input('month', now()->month);
                $year = $request->input('year', now()->year);

                $range = $this->getFlutterWeeklyRange($weekNum, $month, $year);
                $startDate = $range['start'];
                $endDate   = $range['end'];
            }

            $transactions = \App\Models\Transaction::whereBetween('tx_date', [$startDate, $endDate])
                // Catatan: Menu Laba MENGECUALIKAN 'pending' agar klop 100% dengan Menu Penjualan (sebagai Laporan Histori Resmi).
                // Berbeda dengan Dashboard yang menyertakan 'pending' untuk pemantauan real-time.
                ->where(function ($q) {
                    $q->where('payment_status', 'paid')
                      ->orWhere(function ($q2) {
                          $q2->where('payment_method', 'receivable')
                             ->whereIn('payment_status', ['unpaid', 'partial']);
                      });
                })
                ->get();
            $transactionIds = $transactions->pluck('id');

            $totalSales = $transactions->sum('total_amount');
            $qrisTotal = $transactions->where('payment_method', 'qris')->sum('total_amount');
            $midtransTotal = $transactions->where('payment_method', 'midtrans_qris')->sum('total_amount');

            $profitsQuery = \App\Models\Profit::with('product')
                ->whereIn('transaction_id', $transactionIds);

            // Filter Category for Profit
            $category = $request->input('category');
            if ($category && $category !== 'all') {
                $profitsQuery->whereHas('product', function($q) use ($category) {
                    $q->where('category', $category);
                });
                $totalSales = \App\Models\TransactionDetail::whereIn('transaction_id', $transactionIds)
                    ->whereHas('product', function($q) use ($category) {
                        $q->where('category', $category);
                    })
                    ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));
                $qrisFee = 0;
            } else {
                $qrisFee = ($qrisTotal * (self::QRIS_FEE_PERCENT / 100)) + ($midtransTotal * (self::MIDTRANS_QRIS_FEE_PERCENT / 100));
            }

            $profits = $profitsQuery->get();
            $totalProfitRaw = $profits->sum('profit_amount');

            $netProfit = $totalProfitRaw - $qrisFee;
            $totalCost = $totalSales - $totalProfitRaw;

            $totalSalesEgg = \App\Models\TransactionDetail::whereIn('transaction_id', $transactionIds)
                ->whereHas('product', fn($q) => $q->where('category', 'egg'))
                ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));

            $totalSalesRice = \App\Models\TransactionDetail::whereIn('transaction_id', $transactionIds)
                ->whereHas('product', fn($q) => $q->where('category', 'rice'))
                ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));

            $formattedStart = \Carbon\Carbon::parse($startDate)->format('d/m/Y');
            $formattedEnd = \Carbon\Carbon::parse($endDate)->format('d/m/Y');

            $periodDates = \Carbon\CarbonPeriod::create($startDate, $endDate);
            $chartLabels = [];
            $chartData = [];
            $chartSalesEgg = [];
            $chartSalesRice = [];
            $chartProfitEgg = [];
            $chartProfitRice = [];

            foreach ($periodDates as $carbonDate) {
                $date = $carbonDate->toDateString();
                $chartLabels[] = $carbonDate->format('d/m');

                // Transaksi hari itu (Accrual Basis)
                $dayTransactions = \App\Models\Transaction::where('tx_date', $date)
                    ->where(function ($q) {
                        $q->where('payment_status', 'paid')
                          ->orWhere(function ($q2) {
                              $q2->where('payment_method', 'receivable')
                                 ->whereIn('payment_status', ['unpaid', 'partial']);
                          });
                    })
                    ->get();
                $dayTransactionIds = $dayTransactions->pluck('id');

                if ($category && $category !== 'all') {
                    $daySales = \App\Models\TransactionDetail::whereIn('transaction_id', $dayTransactionIds)
                        ->whereHas('product', function($q) use ($category) {
                            $q->where('category', $category);
                        })
                        ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));
                } else {
                    $daySales = $dayTransactions->sum('total_amount');
                }
                
                $dayQrisTotal = $dayTransactions->where('payment_method', 'qris')->sum('total_amount');
                $dayMidtransTotal = $dayTransactions->where('payment_method', 'midtrans_qris')->sum('total_amount');
                
                $dayProfitsQuery = \App\Models\Profit::with('product')
                    ->whereIn('transaction_id', $dayTransactionIds);
                
                if ($category && $category !== 'all') {
                    $dayProfitsQuery->whereHas('product', function($q) use ($category) {
                        $q->where('category', $category);
                    });
                    $dayFee = 0;
                } else {
                    $dayFee = ($dayQrisTotal * (self::QRIS_FEE_PERCENT / 100)) + ($dayMidtransTotal * (self::MIDTRANS_QRIS_FEE_PERCENT / 100));
                }

                $dayProfits = $dayProfitsQuery->get();
                $dayProfitRaw = $dayProfits->sum('profit_amount');

                $dayNetProfit = $dayProfitRaw - $dayFee;
                $dayCost = $daySales - $dayProfitRaw;

                $dayEggProfitRaw = $dayProfits->filter(function($p) { return $p->product && $p->product->category == 'egg'; })->sum('profit_amount');
                $dayRiceProfitRaw = $dayProfits->filter(function($p) { return $p->product && $p->product->category == 'rice'; })->sum('profit_amount');

                $daySalesEgg = \App\Models\TransactionDetail::whereIn('transaction_id', $dayTransactionIds)
                    ->whereHas('product', fn($q) => $q->where('category', 'egg'))
                    ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));
                $daySalesRice = \App\Models\TransactionDetail::whereIn('transaction_id', $dayTransactionIds)
                    ->whereHas('product', fn($q) => $q->where('category', 'rice'))
                    ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));

                $chartData[] = [
                    'date' => $date,
                    'total_sales' => (int) $daySales,
                    'total_cost' => (int) $dayCost,
                    'qris_fee' => (int) $dayFee,
                    'net_profit' => (int) $dayNetProfit,
                ];

                $chartSalesEgg[] = (int) $daySalesEgg;
                $chartSalesRice[] = (int) $daySalesRice;
                $chartProfitEgg[] = (int) $dayEggProfitRaw;
                $chartProfitRice[] = (int) $dayRiceProfitRaw;
            }

            $profitChart = [
                'labels' => $chartLabels,
            ];

            if (!$category || $category === 'all') {
                $profitChart['by_all'] = [
                    'total_sales' => array_column($chartData, 'total_sales'),
                    'net_profit' => array_column($chartData, 'net_profit'),
                ];
                $profitChart['by_egg'] = [
                    'total_sales' => $chartSalesEgg,
                    'net_profit' => $chartProfitEgg,
                ];
                $profitChart['by_rice'] = [
                    'total_sales' => $chartSalesRice,
                    'net_profit' => $chartProfitRice,
                ];
            } else if ($category === 'egg') {
                $profitChart['by_egg'] = [
                    'total_sales' => $chartSalesEgg,
                    'net_profit' => $chartProfitEgg,
                ];
            } else if ($category === 'rice') {
                $profitChart['by_rice'] = [
                    'total_sales' => $chartSalesRice,
                    'net_profit' => $chartProfitRice,
                ];
            }

            return $this->success([
                'period' => 'weekly',
                'title' => 'Laba Mingguan - ' . $formattedStart . ' s/d ' . $formattedEnd,
                'date_range' => ['start' => $startDate, 'end' => $endDate],
                'cards' => [
                    'total_sales' => [
                        'name' => 'Total Penjualan',
                        'amount' => (int) $totalSales,
                        'amount_formatted' => 'Rp ' . number_format($totalSales, 0, ',', '.'),
                    ],
                    'total_cost' => [
                        'name' => 'Total Modal (HPP)',
                        'amount' => (int) $totalCost,
                        'amount_formatted' => 'Rp ' . number_format($totalCost, 0, ',', '.'),
                    ],
                    'net_profit' => [
                        'name' => 'Laba Bersih',
                        'amount' => (int) $netProfit,
                        'amount_formatted' => 'Rp ' . number_format($netProfit, 0, ',', '.'),
                    ],
                ],
                'profit_by_category' => $this->formatProfitByCategory($profits, $totalSalesEgg, $totalSalesRice, $totalSales),
                'chart' => $profitChart,
                'summary' => [
                    'total_sales' => (int) $totalSales,
                    'total_cost' => (int) $totalCost,
                    'qris_fee' => (int) $qrisFee,
                    'net_profit' => (int) $netProfit,
                ],
            ], 'Data laba mingguan berhasil dimuat', 200);

        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Penjualan Bulanan
     * GET /api/owner/profit/monthly
     */
    public function monthly(Request $request)
    {
        try {
            // --- NEW: Parameter Mapping ---
            if ($request->has('product_category')) {
                $cat = $request->product_category;
                if ($cat === 'telur') $request->merge(['category' => 'egg']);
                elseif ($cat === 'beras') $request->merge(['category' => 'rice']);
                elseif ($cat === 'all') $request->merge(['category' => 'all']);
            }
            // ------------------------------

            $request->validate([
                'month' => 'nullable|integer|min:1|max:12',
                'year' => 'nullable|integer|min:2020|max:2030',
                'category' => 'nullable|string|in:all,egg,rice',
            ]);

            $month = $request->input('month', now()->month);
            $year = $request->input('year', now()->year);

            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
            $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

            $transactions = \App\Models\Transaction::whereBetween('tx_date', [$startDate, $endDate])
                ->where(function ($q) {
                    $q->where('payment_status', 'paid')
                      ->orWhere(function ($q2) {
                          $q2->where('payment_method', 'receivable')
                             ->whereIn('payment_status', ['unpaid', 'partial']);
                      });
                })
                ->get();
            $transactionIds = $transactions->pluck('id');

            $totalSales = $transactions->sum('total_amount');
            $qrisTotal = $transactions->where('payment_method', 'qris')->sum('total_amount');
            $midtransTotal = $transactions->where('payment_method', 'midtrans_qris')->sum('total_amount');

            $profitsQuery = \App\Models\Profit::with('product')
                ->whereIn('transaction_id', $transactionIds);

            // Filter Category for Profit
            $category = $request->input('category');
            if ($category && $category !== 'all') {
                $profitsQuery->whereHas('product', function($q) use ($category) {
                    $q->where('category', $category);
                });
                $totalSales = \App\Models\TransactionDetail::whereIn('transaction_id', $transactionIds)
                    ->whereHas('product', function($q) use ($category) {
                        $q->where('category', $category);
                    })
                    ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));
                $qrisFee = 0;
            } else {
                $qrisFee = ($qrisTotal * (self::QRIS_FEE_PERCENT / 100)) + ($midtransTotal * (self::MIDTRANS_QRIS_FEE_PERCENT / 100));
            }

            $profits = $profitsQuery->get();
            $totalProfitRaw = $profits->sum('profit_amount');

            $netProfit = $totalProfitRaw - $qrisFee;
            $totalCost = $totalSales - $totalProfitRaw;

            $totalSalesEgg = \App\Models\TransactionDetail::whereIn('transaction_id', $transactionIds)
                ->whereHas('product', fn($q) => $q->where('category', 'egg'))
                ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));

            $totalSalesRice = \App\Models\TransactionDetail::whereIn('transaction_id', $transactionIds)
                ->whereHas('product', fn($q) => $q->where('category', 'rice'))
                ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));
                
            // Grafik bulanan
            $daysInMonth = Carbon::createFromDate($year, $month, 1)->daysInMonth;
            $chartPoints = range(1, $daysInMonth);
            $chartLabels = $chartPoints;
            $chartData = [];
            $chartSalesEgg = [];
            $chartSalesRice = [];
            $chartProfitEgg = [];
            $chartProfitRice = [];

            foreach ($chartPoints as $day) {
                $date = Carbon::createFromDate($year, $month, $day)->toDateString();

                // Transaksi hari itu
                $dayTransactions = \App\Models\Transaction::where('tx_date', $date)
                    // Catatan: Menu Laba MENGECUALIKAN 'pending' agar klop 100% dengan Menu Penjualan (sebagai Laporan Histori Resmi).
                // Berbeda dengan Dashboard yang menyertakan 'pending' untuk pemantauan real-time.
                ->where(function ($q) {
                    $q->where('payment_status', 'paid')
                      ->orWhere(function ($q2) {
                          $q2->where('payment_method', 'receivable')
                             ->whereIn('payment_status', ['unpaid', 'partial']);
                      });
                })
                    ->get();
                $dayTransactionIds = $dayTransactions->pluck('id');

                if ($category && $category !== 'all') {
                    $daySales = \App\Models\TransactionDetail::whereIn('transaction_id', $dayTransactionIds)
                        ->whereHas('product', function($q) use ($category) {
                            $q->where('category', $category);
                        })
                        ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));
                } else {
                    $daySales = $dayTransactions->sum('total_amount');
                }

                $dayQrisTotal = $dayTransactions->where('payment_method', 'qris')->sum('total_amount');
                $dayMidtransTotal = $dayTransactions->where('payment_method', 'midtrans_qris')->sum('total_amount');

                $dayProfitsQuery = \App\Models\Profit::with('product')
                    ->whereIn('transaction_id', $dayTransactionIds);

                if ($category && $category !== 'all') {
                    $dayProfitsQuery->whereHas('product', function($q) use ($category) {
                        $q->where('category', $category);
                    });
                    $dayFee = 0;
                } else {
                    $dayFee = ($dayQrisTotal * (self::QRIS_FEE_PERCENT / 100)) + ($dayMidtransTotal * (self::MIDTRANS_QRIS_FEE_PERCENT / 100));
                }

                $dayProfits = $dayProfitsQuery->get();
                $dayProfitRaw = $dayProfits->sum('profit_amount');

                $dayNetProfit = $dayProfitRaw - $dayFee;
                $dayCost = $daySales - $dayProfitRaw;

                $dayEggProfitRaw = $dayProfits->filter(function($p) { return $p->product && $p->product->category == 'egg'; })->sum('profit_amount');
                $dayRiceProfitRaw = $dayProfits->filter(function($p) { return $p->product && $p->product->category == 'rice'; })->sum('profit_amount');

                $daySalesEgg = \App\Models\TransactionDetail::whereIn('transaction_id', $dayTransactionIds)
                    ->whereHas('product', fn($q) => $q->where('category', 'egg'))
                    ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));
                $daySalesRice = \App\Models\TransactionDetail::whereIn('transaction_id', $dayTransactionIds)
                    ->whereHas('product', fn($q) => $q->where('category', 'rice'))
                    ->sum(\Illuminate\Support\Facades\DB::raw('price * quantity'));

                $chartData[] = [
                    'date' => $date,
                    'day' => $day,
                    'total_sales' => (int) $daySales,
                    'total_cost' => (int) $dayCost,
                    'qris_fee' => (int) $dayFee,
                    'net_profit' => (int) $dayNetProfit,
                ];

                $chartSalesEgg[] = (int) $daySalesEgg;
                $chartSalesRice[] = (int) $daySalesRice;
                $chartProfitEgg[] = (int) $dayEggProfitRaw;
                $chartProfitRice[] = (int) $dayRiceProfitRaw;
            }

            $profitChart = [
                'labels' => $chartLabels,
            ];

            if (!$category || $category === 'all') {
                $profitChart['by_all'] = [
                    'total_sales' => array_column($chartData, 'total_sales'),
                    'net_profit' => array_column($chartData, 'net_profit'),
                ];
                $profitChart['by_egg'] = [
                    'total_sales' => $chartSalesEgg,
                    'net_profit' => $chartProfitEgg,
                ];
                $profitChart['by_rice'] = [
                    'total_sales' => $chartSalesRice,
                    'net_profit' => $chartProfitRice,
                ];
            } else if ($category === 'egg') {
                $profitChart['by_egg'] = [
                    'total_sales' => $chartSalesEgg,
                    'net_profit' => $chartProfitEgg,
                ];
            } else if ($category === 'rice') {
                $profitChart['by_rice'] = [
                    'total_sales' => $chartSalesRice,
                    'net_profit' => $chartProfitRice,
                ];
            }

            return $this->success([
                'period' => 'monthly',
                'title' => 'Laba Bulanan - ' . Carbon::createFromDate($year, $month, 1)->format('F Y'),
                'date_range' => ['start' => $startDate, 'end' => $endDate],
                'cards' => [
                    'total_sales' => [
                        'name' => 'Total Penjualan',
                        'amount' => (int) $totalSales,
                        'amount_formatted' => 'Rp ' . number_format($totalSales, 0, ',', '.'),
                    ],
                    'total_cost' => [
                        'name' => 'Total Modal (HPP)',
                        'amount' => (int) $totalCost,
                        'amount_formatted' => 'Rp ' . number_format($totalCost, 0, ',', '.'),
                    ],
                    'net_profit' => [
                        'name' => 'Laba Bersih',
                        'amount' => (int) $netProfit,
                        'amount_formatted' => 'Rp ' . number_format($netProfit, 0, ',', '.'),
                    ],
                ],
                'profit_by_category' => $this->formatProfitByCategory($profits, $totalSalesEgg, $totalSalesRice, $totalSales),
                'chart' => $profitChart,
                'summary' => [
                    'total_sales' => (int) $totalSales,
                    'total_cost' => (int) $totalCost,
                    'qris_fee' => (int) $qrisFee,
                    'net_profit' => (int) $netProfit,
                ],
            ], 'Data laba bulanan berhasil dimuat', 200);

        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan: ' . $e->getMessage(), null, 500);
        }
    }
}
