<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\Receivable;
use App\Models\BadProduct;
use App\Models\Payable;
use App\Models\Profit;
use App\Traits\ApiResponseTrait;
use App\Traits\DateRangeHelper;
use App\Services\ProfitCalculatorService;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SalesExport;

class ReportController extends Controller
{
    use ApiResponseTrait, DateRangeHelper, \App\Traits\SalesReportCalculator;

    protected $profitService;
    protected $logger;

    public function __construct(ProfitCalculatorService $profitService, SerenityLoggerService $logger)
    {
        $this->profitService = $profitService;
        $this->logger = $logger;
    }

    public function salesReport(Request $request)
    {
        try {
            // --- NEW: Parameter Mapping untuk product_category dan payment_method ---
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
            // ------------------------------------------------------------------------
            $request->validate([
                'period' => 'required|in:daily,weekly,monthly,custom',
                'start_date' => 'required_if:period,custom|date',
                'end_date' => 'required_if:period,custom|date|after_or_equal:start_date',
                'category' => 'nullable|string|in:all,egg,rice',
                'payment_method' => 'nullable|string|in:all,cash,transfer,qris,midtrans_qris,receivable',
            ]);

            $query = Transaction::with(['cashier', 'details.product'])
                ->whereNotNull('tx_date')
                ->validSales();
            
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
            $hasCategoryFilter = $category && $category !== 'all';

            if ($hasCategoryFilter) {
                $query->whereHas('details.product', function($q) use ($category) {
                    $q->where('category', $category);
                });
                
                $query->with(['details' => function($q) use ($category) {
                    $q->whereHas('product', function($q2) use ($category) {
                        $q2->where('category', $category);
                    });
                }]);
            }

            $startDate = null;
            $endDate = null;

            switch ($request->period) {
                case 'daily':
                    $date = $request->input('date', now()->toDateString());
                    $query->where('tx_date', $date);
                    $title = 'Laporan Penjualan Harian - ' . \Carbon\Carbon::parse($date)->format('d/m/Y');
                    $period = 'daily';
                    break;
                case 'weekly':
                    $week  = $request->input('week');
                    $month = $request->input('month', now()->month);
                    $year  = $request->input('year', now()->year);

                    if ($week !== null) {
                        $range = $this->getFlutterWeeklyRange($week, $month, $year);
                        $startDate = $range['start'];
                        $endDate   = $range['end'];
                    } else {
                        $baseDate = \Carbon\Carbon::parse($request->input('date', now()->toDateString()));
                        $startDate = $baseDate->copy()->startOfWeek()->toDateString();
                        $endDate = $baseDate->copy()->endOfWeek()->toDateString();
                    }

                    $query->whereBetween('tx_date', [$startDate, $endDate]);
                    $title = 'Laporan Penjualan Mingguan - ' . \Carbon\Carbon::parse($startDate)->format('d/m/Y') . ' s/d ' . \Carbon\Carbon::parse($endDate)->format('d/m/Y');
                    $period = 'weekly';
                    break;
                case 'monthly':
                    $month = $request->input('month', now()->month);
                    $year  = $request->input('year', now()->year);
                    $startOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth();
                    $startDate = $startOfMonth->toDateString();
                    $endDate = $startOfMonth->copy()->endOfMonth()->toDateString();
                    $query->whereBetween('tx_date', [$startDate, $endDate]);
                    $title = 'Laporan Penjualan Bulanan - ' . $startOfMonth->format('F Y');
                    $period = 'monthly';
                    break;
                case 'custom':
                    $startDate = $request->start_date;
                    $endDate = $request->end_date;
                    $query->whereBetween('tx_date', [$startDate, $endDate]);
                    $title = 'Laporan Penjualan - ' . $startDate . ' s/d ' . $endDate;
                    $period = 'custom';
                    break;
            }

            $summaryQuery = clone $query;
            $transactions = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 10));
            
            if ($hasCategoryFilter) {
                // Transform the paginated transactions to add filtered_amount
                $formattedTransactions = $transactions->getCollection()->map(function ($transaction) {
                    $filteredAmount = 0;
                    foreach ($transaction->details as $detail) {
                        $filteredAmount += $detail->subtotal;
                    }
                    // Attach to the transaction so UI can use it
                    $transaction->filtered_amount = $filteredAmount;
                    $transaction->filtered_amount_formatted = 'Rp ' . number_format($filteredAmount, 0, ',', '.');
                    return $transaction;
                });
                $transactions->setCollection($formattedTransactions);
            }

            // Generate summary dengan Trait (dari summaryQuery, bukan $query yg sdh di-paginate)
            $calculatedSummary = $this->calculateSalesSummary($summaryQuery, $category);

            $summary = [
                'total_transactions' => (clone $summaryQuery)->validSales()->count(),
                'total_omzet_kotor' => $calculatedSummary['total_omzet_kotor'],
                'total_omzet_kotor_formatted' => $calculatedSummary['total_omzet_kotor_formatted'],
                'total_payment_fee' => $calculatedSummary['total_payment_fee'],
                'total_payment_fee_formatted' => $calculatedSummary['total_payment_fee_formatted'],
                'total_omzet_bersih' => $calculatedSummary['total_omzet_bersih'],
                'total_omzet_bersih_formatted' => $calculatedSummary['total_omzet_bersih_formatted'],
            ];
            
            if ($startDate && $endDate) {
                $summary['date_range'] = [
                    'start' => $startDate,
                    'end'   => $endDate,
                ];
            }

            // Generate chart data
            if ($period !== 'daily') {
                $chart = $this->getSalesChartData($period, $request, clone $summaryQuery, $category, $startDate, $endDate);
            } else {
                $chart = [];
            }

            $reportData = [
                'title' => $title,
                'generated_at' => now()->format('d/m/Y H:i:s'),
                'summary' => $summary,
                'transactions' => $transactions,
                'chart' => $chart,
                'has_category_filter' => $hasCategoryFilter,
            ];
            
            $this->logger->info('Owner melihat laporan penjualan', [
                'period' => $request->period,
                'user_id' => $request->user()?->id
            ]);
            
            return $this->success($reportData, 'Laporan penjualan berhasil dimuat', 200);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function profitReport(Request $request)
    {
        try {
            // --- NEW: Parameter Mapping untuk product_category ---
            if ($request->has('product_category')) {
                $cat = $request->product_category;
                if ($cat === 'telur') $request->merge(['category' => 'egg']);
                elseif ($cat === 'beras') $request->merge(['category' => 'rice']);
                elseif ($cat === 'all') $request->merge(['category' => 'all']);
            }
            // ---------------------------------------------------
            $request->validate([
                'period' => 'required|in:daily,weekly,monthly,custom',
                'start_date' => 'required_if:period,custom|date',
                'end_date' => 'required_if:period,custom|date|after_or_equal:start_date',
                'category' => 'nullable|string|in:all,egg,rice',
            ]);

            // EXCLUDE PENDING / FAILED (Murni Transaksi Valid Saja)
            $query = Profit::with(['product', 'transaction.details'])
                ->whereHas('transaction', function ($q) {
                    $q->validSales();
                });
            
            $category = $request->input('category');
            if ($category && $category !== 'all') {
                $query->whereHas('product', function($q) use ($category) {
                    $q->where('category', $category);
                });
            }

            $startDate = null;
            $endDate = null;

            switch ($request->period) {
                case 'daily':
                    $date = $request->input('date', now()->toDateString());
                    $query->where('profit_date_only', $date);
                    $title = 'Laporan Laba Harian - ' . \Carbon\Carbon::parse($date)->format('d/m/Y');
                    break;
                case 'weekly':
                    $week  = $request->input('week');
                    $month = $request->input('month', now()->month);
                    $year  = $request->input('year', now()->year);

                    if ($week !== null) {
                        $range = $this->getFlutterWeeklyRange($week, $month, $year);
                        $startDate = $range['start'];
                        $endDate   = $range['end'];
                    } else {
                        $baseDate = \Carbon\Carbon::parse($request->input('date', now()->toDateString()));
                        $startDate = $baseDate->copy()->startOfWeek()->toDateString();
                        $endDate = $baseDate->copy()->endOfWeek()->toDateString();
                    }

                    $query->whereBetween('profit_date_only', [$startDate, $endDate]);
                    $title = 'Laporan Laba Mingguan - ' . \Carbon\Carbon::parse($startDate)->format('d/m/Y') . ' s/d ' . \Carbon\Carbon::parse($endDate)->format('d/m/Y');
                    break;
                case 'monthly':
                    $month = $request->input('month', now()->month);
                    $year  = $request->input('year', now()->year);
                    $startOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth();
                    $startDate = $startOfMonth->toDateString();
                    $endDate = $startOfMonth->copy()->endOfMonth()->toDateString();
                    $query->whereBetween('profit_date_only', [$startDate, $endDate]);
                    $title = 'Laporan Laba Bulanan - ' . $startOfMonth->format('F Y');
                    break;
                case 'custom':
                    $startDate = $request->start_date;
                    $endDate = $request->end_date;
                    $query->whereBetween('profit_date_only', [$startDate, $endDate]);
                    $title = 'Laporan Laba - ' . $startDate . ' s/d ' . $endDate;
                    break;
            }
            
            // Clone query for aggregate summary calculation before pagination applies LIMIT/OFFSET
            $summaryQuery = clone $query;

            $profits = $query->orderBy('profit_date', 'desc')->orderBy('id', 'desc')->paginate($request->input('per_page', 10));
            
            $totalProfit    = (float) $summaryQuery->sum('profit_amount');
            $realizedProfit = (float) (clone $summaryQuery)
                ->where(function ($q) {
                    $q->where('is_from_receivable', false)
                      ->orWhere('receivable_status', 'paid');
                })
                ->sum('profit_amount');
            $pendingProfit  = (float) (clone $summaryQuery)
                ->where('is_from_receivable', true)
                ->whereIn('receivable_status', ['unpaid', 'partial'])
                ->sum('profit_amount');

            $allProfitsForSummary = (clone $summaryQuery)->with('transaction.details')->get();

            $totalPendapatanKotor = $this->profitService->calculateTotalOmzet($allProfitsForSummary);
            $totalBeban = $this->profitService->calculateTotalBeban($allProfitsForSummary);

            $summary = [
                'total_profit'             => $totalProfit,
                'total_profit_formatted'   => 'Rp ' . number_format($totalProfit, 0, ',', '.'),
                // Laba sudah cair (transaksi tunai / piutang yang sudah lunas)
                'realized_profit'          => $realizedProfit,
                'realized_profit_formatted'=> 'Rp ' . number_format($realizedProfit, 0, ',', '.'),
                // Laba pending (piutang belum/sebagian lunas)
                'pending_profit'           => $pendingProfit,
                'pending_profit_formatted' => 'Rp ' . number_format($pendingProfit, 0, ',', '.'),
                'total_pendapatan_kotor'            => $totalPendapatanKotor,
                'total_pendapatan_kotor_formatted'  => 'Rp ' . number_format($totalPendapatanKotor, 0, ',', '.'),
                'total_beban'                       => $totalBeban,
                'total_beban_formatted'             => 'Rp ' . number_format($totalBeban, 0, ',', '.'),
                'total_quantity_sold'      => $summaryQuery->sum('quantity_sold'),
            ];
            
            if ($startDate && $endDate) {
                $summary['date_range'] = [
                    'start' => $startDate,
                    'end'   => $endDate,
                ];
            }
            
            // Chart Data
            if ($request->period !== 'daily') {
                $chartData = $this->getProfitChartData($request->period, $request, clone $summaryQuery, $category, $startDate, $endDate);
            } else {
                $chartData = [];
            }

            // Format profits dengan field profit_status per baris
            $formattedProfits = $profits->getCollection()->map(function ($profit) {
                $isFromReceivable = (bool) $profit->is_from_receivable;
                $receivableStatus = $profit->receivable_status;
                $profitStatus = (!$isFromReceivable || $receivableStatus === 'paid')
                    ? 'realized'
                    : 'pending';

                // Find transaction detail matching product_id to get price & purchase_price
                $detail = $this->profitService->getTransactionDetail($profit);

                $totalPenjualan = $detail ? (float) ($detail->price * $profit->quantity_sold) : 0.0;
                $totalModal = $detail ? (float) ($detail->purchase_price * $profit->quantity_sold) : 0.0;

                return [
                    'id'                     => $profit->id,
                    'transaction_id'         => $profit->transaction_id,
                    'invoice_number'         => $profit->transaction->invoice_number ?? '-',
                    'product_id'             => $profit->product_id,
                    'product_name'           => $profit->product->name ?? '-',
                    'product_category'       => $profit->product->category ?? '-',
                    'quantity_sold'          => $profit->quantity_sold,
                    'profit_amount'          => (float) $profit->profit_amount,
                    'profit_amount_formatted'=> 'Rp ' . number_format($profit->profit_amount, 0, ',', '.'),
                    'profit_date'            => $profit->profit_date,
                    'profit_date_formatted'  => date('d/m/Y', strtotime($profit->profit_date)),
                    'profit_status'          => $profitStatus,
                    'total_penjualan'        => $totalPenjualan,
                    'total_penjualan_formatted' => 'Rp ' . number_format($totalPenjualan, 0, ',', '.'),
                    'total_modal'            => $totalModal,
                    'total_modal_formatted'  => 'Rp ' . number_format($totalModal, 0, ',', '.'),
                ];
            });
            $profits->setCollection($formattedProfits);

            $reportData = [
                'title'        => $title,
                'generated_at' => now()->format('d/m/Y H:i:s'),
                'summary'      => $summary,
                'profits'      => $profits,
                'chart'        => $chartData,
            ];
            
            return $this->success($reportData, 'Laporan laba berhasil dimuat', 200);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Profit report error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat laporan laba', null, 500);
        }
    }

    public function badProductReport(Request $request)
    {
        try {
            $request->validate([
                'period' => 'sometimes|in:daily,weekly,monthly,custom',
                'start_date' => 'required_if:period,custom|date',
                'end_date' => 'required_if:period,custom|date|after_or_equal:start_date',
            ]);
            
            $query = BadProduct::with(['product', 'reportedBy']);
            
            if ($request->period) {
                switch ($request->period) {
                    case 'daily':
                        $query->whereDate('incident_date', now()->toDateString());
                        $title = 'Laporan Barang Rusak Harian - ' . now()->format('d/m/Y');
                        break;
                    case 'weekly':
                        $query->whereBetween('incident_date', [now()->startOfWeek(), now()->endOfWeek()]);
                        $title = 'Laporan Barang Rusak Mingguan - ' . now()->startOfWeek()->format('d/m/Y') . ' s/d ' . now()->endOfWeek()->format('d/m/Y');
                        break;
                    case 'monthly':
                        $query->whereMonth('incident_date', now()->month)->whereYear('incident_date', now()->year);
                        $title = 'Laporan Barang Rusak Bulanan - ' . now()->format('F Y');
                        break;
                    case 'custom':
                        $query->whereBetween('incident_date', [$request->start_date, $request->end_date]);
                        $title = 'Laporan Barang Rusak - ' . $request->start_date . ' s/d ' . $request->end_date;
                        break;
                }
            } else {
                $title = 'Laporan Barang Rusak - Semua Data';
            }
            
            $badProducts = $query->orderBy('incident_date', 'desc')->paginate($request->input('per_page', 10));
            
            $summary = [
                'total_quantity' => $query->sum('quantity'),
                'total_loss' => $query->sum('loss_amount'),
                'reported_count' => $query->where('reported_to_supplier', true)->count(),
                'unreported_count' => $query->where('reported_to_supplier', false)->count(),
            ];
            
            $reportData = [
                'title' => $title,
                'generated_at' => now()->format('d/m/Y H:i:s'),
                'summary' => $summary,
                'bad_products' => $badProducts,
            ];
            
            return $this->success($reportData, 'Laporan barang rusak berhasil dimuat', 200);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Bad product report error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat laporan barang rusak', null, 500);
        }
    }

    public function receivableReport(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            
            $receivables = Receivable::with('transaction.cashier')
                ->orderBy('due_date', 'asc')
                ->paginate($perPage);
            
            $summary = [
                'total_receivable' => Receivable::where('status', '!=', 'paid')->sum('remaining_debt'),
                'overdue_amount' => Receivable::where('due_date', '<', now())
                    ->where('status', '!=', 'paid')
                    ->sum('remaining_debt'),
                'paid_amount' => Receivable::where('status', 'paid')->sum('total_debt'),
                'total_customers' => Receivable::distinct('customer_name')->count('customer_name'),
                'overdue_count' => Receivable::where('due_date', '<', now())
                    ->where('status', '!=', 'paid')
                    ->count(),
            ];
            
            $reportData = [
                'title' => 'Laporan Piutang Pelanggan',
                'generated_at' => now()->format('d/m/Y H:i:s'),
                'summary' => $summary,
                'receivables' => $receivables,
            ];
            
            return $this->success($reportData, 'Laporan piutang berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Receivable report error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat laporan piutang', null, 500);
        }
    }

    public function payableReport(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            
            $payables = Payable::with('supplier')
                ->orderBy('due_date', 'asc')
                ->paginate($perPage);
            
            $summary = [
                'total_payable' => Payable::where('status', '!=', 'paid')->sum('remaining_debt'),
                'overdue_amount' => Payable::where('due_date', '<', now())
                    ->where('status', '!=', 'paid')
                    ->sum('remaining_debt'),
                'paid_amount' => Payable::where('status', 'paid')->sum('total_debt'),
                'total_suppliers' => Payable::distinct('supplier_id')->count('supplier_id'),
            ];
            
            $reportData = [
                'title' => 'Laporan Hutang ke Supplier',
                'generated_at' => now()->format('d/m/Y H:i:s'),
                'summary' => $summary,
                'payables' => $payables,
            ];
            
            return $this->success($reportData, 'Laporan hutang berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Payable report error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat laporan hutang', null, 500);
        }
    }

    public function exportSalesPDF(Request $request)
    {
        try {
            $data = $this->getSalesReportData($request);
            $pdf = Pdf::loadView('reports.sales-pdf', $data);
            $pdf->setPaper('A4', 'landscape');
            
            $suffix = $data['filename_suffix'] ?? '';
            $filename = 'laporan_penjualan' . $suffix . '_' . date('Ymd_His') . '.pdf';
            
            return $pdf->download($filename);
            
        } catch (\Exception $e) {
            $this->logger->error('Export PDF error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengexport PDF: ' . $e->getMessage(), null, 500);
        }
    }

    public function exportSalesExcel(Request $request)
    {
        try {
            $data = $this->getSalesReportData($request);

            $suffix = $data['filename_suffix'] ?? '';
            $filename = 'laporan_penjualan' . $suffix . '_' . date('Ymd_His') . '.xlsx';

            return Excel::download(new SalesExport($data), $filename);

        } catch (\Exception $e) {
            $this->logger->error('Export Excel error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengexport Excel', null, 500);
        }
    }

    protected function getSalesReportData(Request $request)
    {
        // 1. Simpan nilai asli
        $originalCategory = $request->input('product_category', 'all');
        $originalPayment  = $request->input('payment_method', 'all');

        // 2. Mapping Parameter
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

        $query = Transaction::with(['cashier', 'details.product'])
            ->validSales();
        
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
        $hasCategoryFilter = $category && $category !== 'all';

        if ($hasCategoryFilter) {
            $query->whereHas('details.product', function($q) use ($category) {
                $q->where('category', $category);
            });
            
            $query->with(['details' => function($q) use ($category) {
                $q->whereHas('product', function($q2) use ($category) {
                    $q2->where('category', $category);
                });
            }]);
        }

        if ($request->period === 'custom' && $request->start_date && $request->end_date) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
        } elseif ($request->period === 'daily') {
            $query->whereDate('created_at', $request->input('date', now()->toDateString()));
        } elseif ($request->period === 'weekly') {
            $baseDate = \Carbon\Carbon::parse($request->input('date', now()->toDateString()));
            $query->whereBetween('created_at', [$baseDate->copy()->startOfWeek(), $baseDate->copy()->endOfWeek()]);
        } elseif ($request->period === 'monthly') {
            $month = $request->input('month', now()->month);
            $year  = $request->input('year', now()->year);
            $query->whereMonth('created_at', $month)->whereYear('created_at', $year);
        }

        $transactions = $query->orderBy('created_at', 'desc')->get();
        
        if ($hasCategoryFilter) {
            $transactions = $transactions->map(function ($transaction) {
                $filteredAmount = 0;
                foreach ($transaction->details as $detail) {
                    $filteredAmount += (float) $detail->subtotal;
                }
                $transaction->filtered_amount = $filteredAmount;
                return $transaction;
            });
        }

        // 3. Bangun Judul Dinamis
        $filterLabels = [];
        if ($originalCategory !== 'all') {
            $filterLabels[] = 'Kategori: ' . ucfirst($originalCategory);
        }
        if ($originalPayment !== 'all') {
            $pmLabel = $originalPayment;
            if ($pmLabel === 'tunai') $pmLabel = 'Tunai';
            elseif ($pmLabel === 'kredit') $pmLabel = 'Kredit';
            elseif ($pmLabel === 'transfer_bank') $pmLabel = 'Transfer Bank';
            elseif ($pmLabel === 'qris_statis') $pmLabel = 'QRIS Statis';
            elseif ($pmLabel === 'qris_midtrans') $pmLabel = 'QRIS Midtrans';
            $filterLabels[] = 'Metode: ' . $pmLabel;
        }
        
        $titleSuffix = count($filterLabels) > 0 ? ' (' . implode(', ', $filterLabels) . ')' : '';
        
        $filenameSuffix = '';
        if ($originalCategory !== 'all') {
            $filenameSuffix .= '_' . $originalCategory;
        }
        if ($originalPayment !== 'all') {
            $filenameSuffix .= '_' . $originalPayment;
        }

        $summary = [
            'total_transactions' => $transactions->count(),
            'total_revenue' => $transactions->sum($hasCategoryFilter ? 'filtered_amount' : 'total_amount'),
            'total_revenue_formatted' => 'Rp ' . number_format($transactions->sum($hasCategoryFilter ? 'filtered_amount' : 'total_amount'), 0, ',', '.'),
        ];
        
        return [
            'title' => 'Laporan Penjualan - ' . $this->getStoreName() . $titleSuffix,
            'filename_suffix' => $filenameSuffix,
            'has_category_filter' => $hasCategoryFilter,
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'summary' => $summary,
            'transactions' => $transactions,
        ];
    }

    protected function getSalesChartData($period, Request $request, $baseQuery, $category, $startDate = null, $endDate = null)
    {
        $labels = [];
        $data = [];
        $periodDates = [];
        
        if ($startDate && $endDate) {
            $periodDates = \Carbon\CarbonPeriod::create($startDate, $endDate);
        } else {
            if ($period === 'weekly') {
                $baseDate = \Carbon\Carbon::parse($request?->input('date', now()->toDateString()) ?? now()->toDateString());
                $periodDates = \Carbon\CarbonPeriod::create($baseDate->copy()->startOfWeek(), $baseDate->copy()->endOfWeek());
            } elseif ($period === 'monthly') {
                $month = $request?->input('month', now()->month) ?? now()->month;
                $year = $request?->input('year', now()->year) ?? now()->year;
                $periodDates = \Carbon\CarbonPeriod::create(
                    \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth(),
                    \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth()
                );
            } elseif ($period === 'custom') {
                $start = \Carbon\Carbon::parse($request?->input('start_date') ?? now()->toDateString());
                $end = \Carbon\Carbon::parse($request?->input('end_date') ?? now()->toDateString());
                $periodDates = \Carbon\CarbonPeriod::create($start, $end);
            }
        }

        $weekDaysShort = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

        foreach ($periodDates as $carbonDate) {
            $dateStr = $carbonDate->toDateString();
            
            if ($period === 'weekly') {
                $labels[] = $weekDaysShort[$carbonDate->dayOfWeekIso - 1];
            } else {
                $labels[] = $carbonDate->format('d/m');
            }
            
            $dayQuery = (clone $baseQuery)->where('tx_date', $dateStr);
            $daySummary = $this->calculateSalesSummary($dayQuery, $category);
            
            $data[] = (int) $daySummary['total_omzet_kotor'];
        }

        $maxSales = count($data) > 0 ? (int) max($data) : 0;
        $scaleMax = $maxSales > 0 ? (int) ($maxSales * 1.2) : 1000000;
        $scale = ['min' => 0, 'max' => $scaleMax];
        
        $salesChart = [];
        if (!$category || $category === 'all') {
            $salesChart['all'] = ['data' => $data, 'name' => 'Semua', 'scale' => $scale];
        } else if ($category === 'egg') {
            $salesChart['egg'] = ['data' => $data, 'name' => 'Telur', 'scale' => $scale];
        } else if ($category === 'rice') {
            $salesChart['rice'] = ['data' => $data, 'name' => 'Beras', 'scale' => $scale];
        }

        return [
            'labels' => $labels,
            'sales' => $salesChart,
            'scale' => $scale,
        ];
    }

    protected function getProfitChartData($period, Request $request, $originalQuery, $category, $startDate = null, $endDate = null)
    {
        $labels = [];
        $salesData = [];
        $profitData = [];
        $periodDates = [];
        
        if ($startDate && $endDate) {
            $periodDates = \Carbon\CarbonPeriod::create($startDate, $endDate);
        } else {
            if ($period === 'weekly') {
                $baseDate = \Carbon\Carbon::parse($request->input('date', now()->toDateString()));
                $periodDates = \Carbon\CarbonPeriod::create($baseDate->copy()->startOfWeek(), $baseDate->copy()->endOfWeek());
            } elseif ($period === 'monthly') {
                $month = $request->input('month', now()->month);
                $year = $request->input('year', now()->year);
                $periodDates = \Carbon\CarbonPeriod::create(
                    \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth(),
                    \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth()
                );
            } elseif ($period === 'custom') {
                $start = \Carbon\Carbon::parse($request->input('start_date'));
                $end = \Carbon\Carbon::parse($request->input('end_date'));
                $periodDates = \Carbon\CarbonPeriod::create($start, $end);
            }
        }

        $weekDaysShort = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

        foreach ($periodDates as $carbonDate) {
            $dateStr = $carbonDate->toDateString();
            
            if ($period === 'weekly') {
                $labels[] = $weekDaysShort[$carbonDate->dayOfWeekIso - 1];
            } else {
                $labels[] = $carbonDate->format('d/m');
            }
            
            $dayQuery = (clone $originalQuery)->where('profit_date_only', $dateStr);
            $realizedProfit = (float) (clone $dayQuery)
                ->where(function ($q) {
                    $q->where('is_from_receivable', false)
                      ->orWhere('receivable_status', 'paid');
                })
                ->sum('profit_amount');
                
            $totalSales = (float) (clone $dayQuery)
                ->where(function ($q) {
                    $q->where('is_from_receivable', false)
                      ->orWhere('receivable_status', 'paid');
                })
                ->with('transaction.details')
                ->get()
                ->sum(function($profit) use ($category) {
                    if (!$category || $category === 'all') {
                        return $profit->transaction->total_amount;
                    }
                    return $profit->transaction->details->filter(function($detail) use ($category) {
                        return $detail->product && $detail->product->category === $category;
                    })->sum(function($detail) {
                        return $detail->price * $detail->quantity;
                    });
                });

            $salesData[] = (int) $totalSales;
            $profitData[] = (int) $realizedProfit;
        }

        $chartKey = 'by_' . ($category && $category !== 'all' ? $category : 'all');

        return [
            'labels' => $labels,
            $chartKey => [
                'total_sales' => $salesData,
                'net_profit' => $profitData,
            ]
        ];
    }
}
