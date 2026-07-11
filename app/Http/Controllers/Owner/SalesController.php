<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SalesController extends Controller
{
    use ApiResponseTrait, \App\Traits\DateRangeHelper, \App\Traits\SalesReportCalculator;

    // Potongan fee (%): QRIS statis 0.5%, QRIS Midtrans 0.7%
    private const QRIS_FEE_PERCENT = 0.5;
    private const MIDTRANS_QRIS_FEE_PERCENT = 0.7;

    /**
     * Penjualan Harian - Dengan parameter tanggal opsional
     * GET /api/owner/sales/daily?date=2026-06-14
     */
    public function daily(Request $request)
    {
        try {
            $date = $request->input('date', now()->toDateString());
            $query = Transaction::where('tx_date', $date)
                ->where(function ($q) {
                    $q->where('payment_status', 'paid')
                      ->orWhere(function ($q2) {
                          $q2->where('payment_method', 'receivable')
                             ->whereIn('payment_status', ['unpaid', 'partial']);
                      });
                });


            return $this->buildSalesResponse($query, 'daily', $date, $date);
        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Penjualan Mingguan - Bisa input minggu ke- atau tanggal custom
     * GET /api/owner/sales/weekly?week=1&month=6&year=2026
     * GET /api/owner/sales/weekly?start=2026-06-01&end=2026-06-07
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

            if ($request->has('payment_method')) {
                $pm = $request->payment_method;
                if ($pm === 'tunai') $request->merge(['payment_method' => 'cash']);
                elseif ($pm === 'transfer_bank') $request->merge(['payment_method' => 'transfer']);
                elseif ($pm === 'qris_statis') $request->merge(['payment_method' => 'qris']);
                elseif ($pm === 'qris_midtrans') $request->merge(['payment_method' => 'midtrans_qris']);
                elseif ($pm === 'kredit') $request->merge(['payment_method' => 'receivable']);
                elseif ($pm === 'all') $request->merge(['payment_method' => 'all']);
            }
            // ------------------------------

            $request->validate([
                'week' => 'nullable|integer|min:1|max:6',
                'month' => 'nullable|integer|min:1|max:12',
                'year' => 'nullable|integer|min:2020|max:2030',
                'start' => 'nullable|date',
                'end' => 'nullable|date|after_or_equal:start',
                'category' => 'nullable|string|in:all,egg,rice',
                'payment_method' => 'nullable|string|in:all,cash,transfer,qris,midtrans_qris,receivable',
            ]);


            // hitung startDate dan endDate
            if ($request->has('start') && $request->has('end')) {
                // Custom range
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

            // PAID OR receivable (unpaid/partial)
            $query = Transaction::whereBetween('tx_date', [$startDate, $endDate])
                ->where(function ($q) {
                    $q->where('payment_status', 'paid')
                      ->orWhere(function ($q2) {
                          $q2->where('payment_method', 'receivable')
                             ->whereIn('payment_status', ['unpaid', 'partial']);
                      });
                });


            // Filter Payment Method
            if ($request->filled('payment_method') && $request->payment_method !== 'all') {
                if ($request->payment_method === 'qris') {
                    $query->whereIn('payment_method', ['qris', 'qris_statis']);
                } else {
                    $query->where('payment_method', $request->payment_method);
                }
            }

            // Filter Category
            $category = $request->input('category');
            if ($category && $category !== 'all') {
                $query->whereHas('details.product', function($q) use ($category) {
                    $q->where('category', $category);
                });
            }

            $response = $this->buildSalesResponse($query, 'weekly', $startDate, $endDate, null, null, $category);

            $chartLabels = [];
            $chartDataAll = [];
            $chartDataEgg = [];
            $chartDataRice = [];

            $periodDates = \Carbon\CarbonPeriod::create($startDate, $endDate);

            foreach ($periodDates as $carbonDate) {
                $date = $carbonDate->toDateString();
                $chartLabels[] = $carbonDate->format('d/m');

                // Base query for this date
                $dayQuery = Transaction::where('tx_date', $date)
                    ->where(function ($q) {
                        $q->where('payment_status', 'paid')
                          ->orWhere(function ($q2) {
                              $q2->where('payment_method', 'receivable')
                                 ->whereIn('payment_status', ['unpaid', 'partial']);
                          });
                    });

                // Terapkan filter payment_method jika ada
                if ($request->filled('payment_method') && $request->payment_method !== 'all') {
                    if ($request->payment_method === 'qris') {
                        $dayQuery->whereIn('payment_method', ['qris', 'qris_statis']);
                    } else {
                        $dayQuery->where('payment_method', $request->payment_method);
                    }
                }

                // Hitung via calculateSalesSummary agar konsisten 100%
                $summaryAll = $this->calculateSalesSummary($dayQuery, 'all');
                $summaryEgg = $this->calculateSalesSummary($dayQuery, 'egg');
                $summaryRice = $this->calculateSalesSummary($dayQuery, 'rice');

                $chartDataAll[] = (int) $summaryAll['total_omzet_kotor'];
                $chartDataEgg[] = (int) $summaryEgg['total_omzet_kotor'];
                $chartDataRice[] = (int) $summaryRice['total_omzet_kotor'];
            }
            $chartDays = $chartLabels;

            $maxSalesAll = count($chartDataAll) > 0 ? (int) max($chartDataAll) : 0;
            $maxSalesEgg = count($chartDataEgg) > 0 ? (int) max($chartDataEgg) : 0;
            $maxSalesRice = count($chartDataRice) > 0 ? (int) max($chartDataRice) : 0;
            
            $maxOverall = 0;
            if (!$category || $category === 'all') {
                $maxOverall = max($maxSalesAll, $maxSalesEgg, $maxSalesRice);
            } else if ($category === 'egg') {
                $maxOverall = $maxSalesEgg;
            } else if ($category === 'rice') {
                $maxOverall = $maxSalesRice;
            }
            
            $scaleMaxAll = $maxSalesAll > 0 ? (int) ($maxSalesAll * 1.2) : 0;
            $scaleMaxEgg = $maxSalesEgg > 0 ? (int) ($maxSalesEgg * 1.2) : 0;
            $scaleMaxRice = $maxSalesRice > 0 ? (int) ($maxSalesRice * 1.2) : 0;

            $salesChart = [];
            if (!$category || $category === 'all') {
                $salesChart['all'] = [
                    'data' => $chartDataAll,
                    'name' => 'Semua',
                    'scale' => ['min' => 0, 'max' => $scaleMaxAll],
                ];
                $salesChart['egg'] = [
                    'data' => $chartDataEgg,
                    'name' => 'Telur',
                    'scale' => ['min' => 0, 'max' => $scaleMaxEgg],
                ];
                $salesChart['rice'] = [
                    'data' => $chartDataRice,
                    'name' => 'Beras',
                    'scale' => ['min' => 0, 'max' => $scaleMaxRice],
                ];
            } else if ($category === 'egg') {
                $salesChart['egg'] = [
                    'data' => $chartDataEgg,
                    'name' => 'Telur',
                    'scale' => ['min' => 0, 'max' => $scaleMaxEgg],
                ];
            } else if ($category === 'rice') {
                $salesChart['rice'] = [
                    'data' => $chartDataRice,
                    'name' => 'Beras',
                    'scale' => ['min' => 0, 'max' => $scaleMaxRice],
                ];
            }

            $response['data']['chart'] = [
                'labels' => $chartDays,
                'sales' => $salesChart,
                'scale' => [
                    'min' => 0,
                    'max' => $maxOverall > 0 ? (int) ($maxOverall * 1.2) : 0,
                ],
            ];

            return $this->success($response['data'], 'Penjualan mingguan berhasil dimuat', 200);
        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Penjualan Bulanan - Custom bulan
     * GET /api/owner/sales/monthly?month=6&year=2026
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

            if ($request->has('payment_method')) {
                $pm = $request->payment_method;
                if ($pm === 'tunai') $request->merge(['payment_method' => 'cash']);
                elseif ($pm === 'transfer_bank') $request->merge(['payment_method' => 'transfer']);
                elseif ($pm === 'qris_statis') $request->merge(['payment_method' => 'qris']);
                elseif ($pm === 'qris_midtrans') $request->merge(['payment_method' => 'midtrans_qris']);
                elseif ($pm === 'kredit') $request->merge(['payment_method' => 'receivable']);
                elseif ($pm === 'all') $request->merge(['payment_method' => 'all']);
            }
            // ------------------------------

            $request->validate([
                'month' => 'nullable|integer|min:1|max:12',
                'year' => 'nullable|integer|min:2020|max:2030',
                'category' => 'nullable|string|in:all,egg,rice',
                'payment_method' => 'nullable|string|in:all,cash,transfer,qris,midtrans_qris,receivable',
            ]);

            $month = $request->input('month', now()->month);
            $year = $request->input('year', now()->year);

            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
            $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

            // PAID OR receivable (unpaid/partial)
            $query = Transaction::whereBetween('tx_date', [$startDate, $endDate])
                ->where(function ($q) {
                    $q->where('payment_status', 'paid')
                      ->orWhere(function ($q2) {
                          $q2->where('payment_method', 'receivable')
                             ->whereIn('payment_status', ['unpaid', 'partial']);
                      });
                });

            // Filter Payment Method
            if ($request->filled('payment_method') && $request->payment_method !== 'all') {
                if ($request->payment_method === 'qris') {
                    $query->whereIn('payment_method', ['qris', 'qris_statis']);
                } else {
                    $query->where('payment_method', $request->payment_method);
                }
            }

            // Filter Category
            $category = $request->input('category');
            if ($category && $category !== 'all') {
                $query->whereHas('details.product', function($q) use ($category) {
                    $q->where('category', $category);
                });
            }

            $response = $this->buildSalesResponse($query, 'monthly', $startDate, $endDate, $month, $year, $category);

            // Generate chart: 1,5,10,15,20,25,31 (atau akhir bulan)
            $daysInMonth = Carbon::createFromDate($year, $month, 1)->daysInMonth;
            $chartPoints = range(1, $daysInMonth);
            $chartLabels = $chartPoints;
            $chartDataAll = [];
            $chartDataEgg = [];
            $chartDataRice = [];

            foreach ($chartPoints as $day) {
                $date = Carbon::createFromDate($year, $month, $day)->toDateString();

                $dayQuery = Transaction::where('tx_date', $date)
                    ->where(function ($q) {
                        $q->where('payment_status', 'paid')
                          ->orWhere(function ($q2) {
                              $q2->where('payment_method', 'receivable')
                                 ->whereIn('payment_status', ['unpaid', 'partial']);
                          });
                    });

                if ($request->filled('payment_method') && $request->payment_method !== 'all') {
                    if ($request->payment_method === 'qris') {
                        $dayQuery->whereIn('payment_method', ['qris', 'qris_statis']);
                    } else {
                        $dayQuery->where('payment_method', $request->payment_method);
                    }
                }

                $summaryAll = $this->calculateSalesSummary($dayQuery, 'all');
                $summaryEgg = $this->calculateSalesSummary($dayQuery, 'egg');
                $summaryRice = $this->calculateSalesSummary($dayQuery, 'rice');

                $chartDataAll[] = (int) $summaryAll['total_omzet_kotor'];
                $chartDataEgg[] = (int) $summaryEgg['total_omzet_kotor'];
                $chartDataRice[] = (int) $summaryRice['total_omzet_kotor'];
            }

            // Hitung scale.max per kategori
            $maxSalesAll = count($chartDataAll) > 0 ? (int) max($chartDataAll) : 0;
            $maxSalesEgg = count($chartDataEgg) > 0 ? (int) max($chartDataEgg) : 0;
            $maxSalesRice = count($chartDataRice) > 0 ? (int) max($chartDataRice) : 0;
            
            $maxOverall = 0;
            if (!$category || $category === 'all') {
                $maxOverall = max($maxSalesAll, $maxSalesEgg, $maxSalesRice);
            } else if ($category === 'egg') {
                $maxOverall = $maxSalesEgg;
            } else if ($category === 'rice') {
                $maxOverall = $maxSalesRice;
            }

            $scaleMaxAll = $maxSalesAll > 0 ? (int) ($maxSalesAll * 1.2) : 0;
            $scaleMaxEgg = $maxSalesEgg > 0 ? (int) ($maxSalesEgg * 1.2) : 0;
            $scaleMaxRice = $maxSalesRice > 0 ? (int) ($maxSalesRice * 1.2) : 0;

            $salesChart = [];
            if (!$category || $category === 'all') {
                $salesChart['all'] = [
                    'data' => $chartDataAll,
                    'name' => 'Semua',
                    'scale' => ['min' => 0, 'max' => $scaleMaxAll],
                ];
                $salesChart['egg'] = [
                    'data' => $chartDataEgg,
                    'name' => 'Telur',
                    'scale' => ['min' => 0, 'max' => $scaleMaxEgg],
                ];
                $salesChart['rice'] = [
                    'data' => $chartDataRice,
                    'name' => 'Beras',
                    'scale' => ['min' => 0, 'max' => $scaleMaxRice],
                ];
            } else if ($category === 'egg') {
                $salesChart['egg'] = [
                    'data' => $chartDataEgg,
                    'name' => 'Telur',
                    'scale' => ['min' => 0, 'max' => $scaleMaxEgg],
                ];
            } else if ($category === 'rice') {
                $salesChart['rice'] = [
                    'data' => $chartDataRice,
                    'name' => 'Beras',
                    'scale' => ['min' => 0, 'max' => $scaleMaxRice],
                ];
            }

            $response['data']['chart'] = [
                'labels' => $chartLabels,
                'sales' => $salesChart,
                'scale' => [
                    'min' => 0,
                    'max' => $maxOverall > 0 ? (int) ($maxOverall * 1.2) : 0,
                ],
            ];

            return $this->success($response['data'], 'Penjualan bulanan berhasil dimuat', 200);
        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Build response untuk data sales
     */
    private function buildSalesResponse($query, $period, $startDate, $endDate, $month = null, $year = null, $categoryId = null)
    {
        // Hitung total per metode pembayaran dengan calculateSalesSummary agar konsisten filter kategori
        $cashTotal = $this->calculateSalesSummary((clone $query)->where('payment_method', 'cash'), $categoryId)['total_omzet_kotor'];
        $transferTotal = $this->calculateSalesSummary((clone $query)->where('payment_method', 'transfer'), $categoryId)['total_omzet_kotor'];
        $qrisTotal = $this->calculateSalesSummary((clone $query)->whereIn('payment_method', ['qris', 'qris_statis']), $categoryId)['total_omzet_kotor'];
        $midtransQrisTotal = $this->calculateSalesSummary((clone $query)->where('payment_method', 'midtrans_qris'), $categoryId)['total_omzet_kotor'];

        $totalTransactions = (clone $query)->count();

        // Gunakan trait agar penghitungan Omzet Kotor anti-bocor (berdasarkan transaction_details)
        $summary = $this->calculateSalesSummary($query, $categoryId);
        $totalOmzet = $summary['total_omzet_kotor'];
        $netOmzet   = $summary['total_omzet_bersih'];
        $totalFee   = $summary['total_payment_fee'];
        
        $qrisFee = $qrisTotal * (self::QRIS_FEE_PERCENT / 100);
        $midtransQrisFee = $midtransQrisTotal * (self::MIDTRANS_QRIS_FEE_PERCENT / 100);

        // Format title
        $title = match($period) {
            'daily' => 'Penjualan Harian - ' . Carbon::parse($startDate)->format('d/m/Y'),
            'weekly' => 'Penjualan Mingguan',
            'monthly' => 'Penjualan Bulanan - ' . Carbon::createFromDate($year ?? now()->year, $month ?? now()->month, 1)->format('F Y'),
            default => 'Penjualan',
        };

        return [
            'data' => [
                'period' => $period,
                'title' => $title,
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
                'summary' => [
                    'total_transactions' => $totalTransactions,
                    'total_transactions_formatted' => $totalTransactions . ' Trx',
                    'total_omzet' => (int) $totalOmzet,
                    'total_omzet_formatted' => 'Rp ' . number_format($totalOmzet, 0, ',', '.'),
                    'net_omzet' => (int) $netOmzet,
                    'net_omzet_formatted' => 'Rp ' . number_format($netOmzet, 0, ',', '.'),
                    'qris_fee' => (int) $totalFee,
                    'qris_fee_formatted' => 'Rp ' . number_format($totalFee, 0, ',', '.'),
                ],
                'by_category' => $this->getSalesByCategory($query, $categoryId),
                'by_payment_method' => [
                    'cash' => [
                        'name' => 'Tunai',
                        'amount' => (int) $cashTotal,
                        'amount_formatted' => 'Rp ' . number_format($cashTotal, 0, ',', '.'),
                        'percentage' => $totalOmzet > 0 ? round(($cashTotal / $totalOmzet) * 100, 1) : 0,
                    ],
                    'transfer' => [
                        'name' => 'Transfer Bank',
                        'amount' => (int) $transferTotal,
                        'amount_formatted' => 'Rp ' . number_format($transferTotal, 0, ',', '.'),
                        'percentage' => $totalOmzet > 0 ? round(($transferTotal / $totalOmzet) * 100, 1) : 0,
                    ],
                    'qris' => [
                        'name' => 'QRIS',
                        'amount' => (int) $qrisTotal,
                        'amount_formatted' => 'Rp ' . number_format($qrisTotal, 0, ',', '.'),
                        'percentage' => $totalOmzet > 0 ? round(($qrisTotal / $totalOmzet) * 100, 1) : 0,
                        'fee_percent' => self::QRIS_FEE_PERCENT,
                        'fee_amount' => (int) $qrisFee,
                    ],
                    'midtrans_qris' => [
                        'name' => 'QRIS Midtrans',
                        'amount' => (int) $midtransQrisTotal,
                        'amount_formatted' => 'Rp ' . number_format($midtransQrisTotal, 0, ',', '.'),
                        'percentage' => $totalOmzet > 0 ? round(($midtransQrisTotal / $totalOmzet) * 100, 1) : 0,
                        'fee_percent' => self::MIDTRANS_QRIS_FEE_PERCENT,
                        'fee_amount' => (int) $midtransQrisFee,
                    ],
                ],
            ],
        ];
    }

    /**
     * Get sales breakdown by category (all/egg/rice)
     */
    private function getSalesByCategory($query, $categoryId = null): array
    {
        $eggSales = ($categoryId === 'rice') ? 0 : (int) $this->calculateSalesSummary($query, 'egg')['total_omzet_kotor'];
        $riceSales = ($categoryId === 'egg') ? 0 : (int) $this->calculateSalesSummary($query, 'rice')['total_omzet_kotor'];

        $total = $eggSales + $riceSales;

        return [
            'all' => [
                'name' => 'Semua Produk',
                'amount' => (int) $total,
                'amount_formatted' => 'Rp ' . number_format($total, 0, ',', '.'),
                'percentage' => 100,
            ],
            'egg' => [
                'name' => 'Telur',
                'amount' => (int) $eggSales,
                'amount_formatted' => 'Rp ' . number_format($eggSales, 0, ',', '.'),
                'percentage' => $total > 0 ? round(($eggSales / $total) * 100, 1) : 0,
            ],
            'rice' => [
                'name' => 'Beras',
                'amount' => (int) $riceSales,
                'amount_formatted' => 'Rp ' . number_format($riceSales, 0, ',', '.'),
                'percentage' => $total > 0 ? round(($riceSales / $total) * 100, 1) : 0,
            ],
        ];
    }

    /**
     * Get sales by category for a single date
     */
    private function getSalesByCategoryInDate(string $date, string $category): float
    {
        return TransactionDetail::whereHas('transaction', function ($q) use ($date) {
                $q->where('tx_date', $date)
                  ->where(function ($q2) {
                      $q2->where('payment_status', 'paid')
                         ->orWhere(function ($q3) {
                             $q3->where('payment_method', 'receivable')
                                ->whereIn('payment_status', ['unpaid', 'partial']);
                         });
                  });
            })
            ->whereHas('product', fn($q) => $q->where('category', $category))
            ->sum(DB::raw('price * quantity'));
    }
}