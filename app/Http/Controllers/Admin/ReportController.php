<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\BadProduct;
use App\Models\Profit;
use App\Traits\ApiResponseTrait;
use App\Traits\DateRangeHelper;
use App\Services\ProfitCalculatorService;
use App\Services\SerenityLoggerService;
use App\Exports\SalesExport;
use App\Exports\ProfitExport;
use App\Exports\ReceivableExport;
use App\Exports\BadProductExport;
use App\Exports\StockExport;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    use ApiResponseTrait, \App\Traits\SalesReportCalculator, DateRangeHelper;

    protected $profitService;
    protected $logger;

    public function __construct(ProfitCalculatorService $profitService, SerenityLoggerService $logger)
    {
        $this->profitService = $profitService;
        $this->logger = $logger;
    }

    /**
     * Laporan Penjualan dengan detail item per transaksi
     */
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
                ->whereIn('payment_status', ['paid', 'partial', 'unpaid']);

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

            // PERBAIKAN: Gunakan tx_date (Tipe DATE) alih-alih created_at (DATETIME)
            switch ($request->period) {
                case 'daily':
                    $date = $request->input('date', now()->toDateString());
                    $query->where('tx_date', $date);
                    $title = 'Laporan Penjualan Harian - ' . \Carbon\Carbon::parse($date)->format('d/m/Y');
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
                        // Fallback: gunakan parameter date
                        $baseDate  = \Carbon\Carbon::parse($request->input('date', now()->toDateString()));
                        $startDate = $baseDate->copy()->startOfWeek()->toDateString();
                        $endDate   = $baseDate->copy()->endOfWeek()->toDateString();
                    }

                    $query->whereBetween('tx_date', [$startDate, $endDate]);
                    $title = 'Laporan Penjualan Mingguan - '
                        . \Carbon\Carbon::parse($startDate)->format('d/m/Y')
                        . ' s/d '
                        . \Carbon\Carbon::parse($endDate)->format('d/m/Y');
                    break;
                case 'monthly':
                    $month = $request->input('month', now()->month);
                    $year = $request->input('year', now()->year);
                    $startOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
                    $endOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();
                    $query->whereBetween('tx_date', [$startOfMonth, $endOfMonth]);
                    $title = 'Laporan Penjualan Bulanan - ' . \Carbon\Carbon::createFromDate($year, $month, 1)->format('F Y');
                    break;
                case 'custom':
                    $query->whereBetween('tx_date', [$request->start_date, $request->end_date]);
                    $title = 'Laporan Penjualan - ' . $request->start_date . ' s/d ' . $request->end_date;
                    break;
            }
            
            // KRITIS: clone SEBELUM paginate() supaya summary tidak kena LIMIT/OFFSET
            $summaryQuery = clone $query;

            $transactions = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 10));
            
            // Format data dengan detail item per transaksi
            $formattedTransactions = $transactions->getCollection()->map(function ($transaction) {
                $items = [];
                foreach ($transaction->details as $detail) {
                    $product = $detail->product;
                    $unitLabel = $this->getUnitLabel($product->unit ?? '-');
                    
                    $items[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'category' => $product->category,
                        'category_label' => $product->category == 'egg' ? 'Telur' : 'Beras',
                        'quantity' => $detail->quantity,
                        'unit' => $product->unit,
                        'unit_label' => $unitLabel,
                        'price_per_unit' => $detail->price,
                        'subtotal' => $detail->subtotal,
                    ];
                }
                
                return [
                    'id' => $transaction->id,
                    'invoice_number' => $transaction->invoice_number,
                    'created_at' => $transaction->created_at,
                    'created_at_formatted' => $transaction->created_at->format('d/m/Y H:i:s'),
                    'cashier_name' => $transaction->cashier->name ?? '-',
                    'payment_method' => $transaction->payment_method,
                    'payment_method_label' => $this->getPaymentMethodLabel($transaction->payment_method),
                    'payment_status' => $transaction->payment_status,
                    'payment_status_label' => $this->getPaymentStatusLabel($transaction->payment_status),
                    'total_amount' => (float) $transaction->total_amount,
                    'total_amount_formatted' => 'Rp ' . number_format($transaction->total_amount, 0, ',', '.'),
                    'paid_amount' => (float) $transaction->paid_amount,
                    'paid_amount_formatted' => 'Rp ' . number_format($transaction->paid_amount, 0, ',', '.'),
                    'change_due' => (float) $transaction->change_due,
                    'change_due_formatted' => 'Rp ' . number_format($transaction->change_due, 0, ',', '.'),
                    'items' => $items,
                    'total_items' => count($items),
                ];
            });
            
            // Update collection dengan data yang sudah diformat
            $transactions->setCollection($formattedTransactions);
            
            // Semua kalkulasi summary dari $summaryQuery (TIDAK kena LIMIT/OFFSET paginate)
            $totalItemsSold = TransactionDetail::whereIn('transaction_id', $summaryQuery->pluck('id'))->sum('quantity');
            
            // Generate summary dengan Trait baru (dari summaryQuery, bukan $query yg sdh di-paginate)
            $calculatedSummary = $this->calculateSalesSummary($summaryQuery, $category);
            
            $summary = [
                'total_transactions' => (clone $summaryQuery)->whereIn('payment_status', ['paid', 'partial', 'unpaid'])->count(),
                'total_omzet_kotor' => $calculatedSummary['total_omzet_kotor'],
                'total_omzet_kotor_formatted' => $calculatedSummary['total_omzet_kotor_formatted'],
                'total_payment_fee' => $calculatedSummary['total_payment_fee'],
                'total_payment_fee_formatted' => $calculatedSummary['total_payment_fee_formatted'],
                'total_omzet_bersih' => $calculatedSummary['total_omzet_bersih'],
                'total_omzet_bersih_formatted' => $calculatedSummary['total_omzet_bersih_formatted'],
                'cash_payments' => (float) $this->calculateSalesSummary((clone $summaryQuery)->where('payment_method', 'cash'), $category)['total_omzet_kotor'],
                'transfer_payments' => (float) $this->calculateSalesSummary((clone $summaryQuery)->where('payment_method', 'transfer'), $category)['total_omzet_kotor'],
                'qris_biasa_payments' => (float) $this->calculateSalesSummary((clone $summaryQuery)->whereIn('payment_method', ['qris', 'qris_statis', 'qris_biasa']), $category)['total_omzet_kotor'],
                'midtrans_qris_payments' => (float) $this->calculateSalesSummary((clone $summaryQuery)->where('payment_method', 'midtrans_qris'), $category)['total_omzet_kotor'],
                'receivable_payments' => (float) $this->calculateSalesSummary((clone $summaryQuery)->where('payment_method', 'receivable'), $category)['total_omzet_kotor'],
                'total_items_sold' => $totalItemsSold,
            ];


            if ($request->period !== 'daily') {
                $chartStartDate = $startDate ?? ($startOfMonth ?? ($request->start_date ?? null));
                $chartEndDate = $endDate ?? ($endOfMonth ?? ($request->end_date ?? null));
                
                $chartData = $this->getSalesChartData($request->period, $request, clone $summaryQuery, $category, $chartStartDate, $chartEndDate);
            } else {
                $chartData = [];
            }
            
            return $this->success([
                'title' => $title,
                'generated_at' => now()->format('d/m/Y H:i:s'),
                'summary' => $summary,
                'transactions' => $transactions,
                'chart' => $chartData,
            ], 'Laporan penjualan berhasil dimuat', 200);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Sales report error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat laporan penjualan: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Laporan Stok Barang
     */
    public function stockReport(Request $request)
    {
        try {
            $category = $request->input('category');
            
            $query = Product::with('supplier');
            
            if ($category && in_array($category, ['egg', 'rice'])) {
                $query->where('category', $category);
            }
            
            $products = $query->orderBy('name')->get();
            
            $formattedProducts = $products->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'category_label' => $product->category == 'egg' ? 'Telur' : 'Beras',
                    'unit' => $product->unit,
                    'unit_label' => $this->getUnitLabel($product->unit),
                    'stock' => $product->stock,
                    'min_stock' => $product->min_stock,
                    'purchase_price' => (float) $product->purchase_price,
                    'purchase_price_formatted' => 'Rp ' . number_format($product->purchase_price, 0, ',', '.'),
                    'selling_price' => (float) $product->selling_price,
                    'selling_price_formatted' => 'Rp ' . number_format($product->selling_price, 0, ',', '.'),
                    'profit_per_unit' => (float) $product->profit_per_unit,
                    'profit_per_unit_formatted' => 'Rp ' . number_format($product->profit_per_unit, 0, ',', '.'),
                    'status' => $product->stock <= $product->min_stock ? 'Hampir Habis' : 'Aman',
                    'status_color' => $product->stock <= $product->min_stock ? 'warning' : 'success',
                    'supplier_name' => $product->supplier->name ?? '-',
                ];
            });
            
            $summary = [
                'total_products' => $products->count(),
                'total_stock_value' => (float) $products->sum(function($p) {
                    return $p->stock * $p->purchase_price;
                }),
                'total_stock_value_formatted' => 'Rp ' . number_format($products->sum(function($p) {
                    return $p->stock * $p->purchase_price;
                }), 0, ',', '.'),
                'total_selling_value' => (float) $products->sum(function($p) {
                    return $p->stock * $p->selling_price;
                }),
                'total_selling_value_formatted' => 'Rp ' . number_format($products->sum(function($p) {
                    return $p->stock * $p->selling_price;
                }), 0, ',', '.'),
                'low_stock_count' => $products->where('stock', '<=', 'min_stock')->count(),
            ];
            
            return $this->success([
                'title' => 'Laporan Stok Barang',
                'generated_at' => now()->format('d/m/Y H:i:s'),
                'summary' => $summary,
                'products' => $formattedProducts,
            ], 'Laporan stok berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Stock report error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat laporan stok: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Laporan Laba
     */
        public function profitReport(Request $request)
    {
        try {
            if ($request->has('product_category')) {
                $cat = $request->product_category;
                if ($cat === 'telur') $request->merge(['category' => 'egg']);
                elseif ($cat === 'beras') $request->merge(['category' => 'rice']);
                elseif ($cat === 'all') $request->merge(['category' => 'all']);
            }
            
            $request->validate([
                'period' => 'required|in:daily,weekly,monthly,custom',
                'start_date' => 'required_if:period,custom|date',
                'end_date' => 'required_if:period,custom|date|after_or_equal:start_date',
                'category' => 'nullable|string|in:all,egg,rice',
            ]);
            
            // EXCLUDE PENDING / FAILED (Murni Transaksi Valid Saja)
            $query = Profit::with(['product', 'transaction.details'])
                ->whereHas('transaction', function ($q) {
                    $q->whereIn('payment_status', ['paid', 'partial', 'unpaid']);
                });

            $category = $request->input('category');
            if ($category && $category !== 'all') {
                $query->whereHas('product', function($q) use ($category) {
                    $q->where('category', $category);
                });
            }

            $profitType = $request->input('profit_type');
            if ($profitType === 'realized') {
                $query->where(function ($q) {
                    $q->where('is_from_receivable', false)
                      ->orWhere('receivable_status', 'paid');
                });
            } elseif ($profitType === 'pending') {
                $query->where('is_from_receivable', true)
                      ->whereIn('receivable_status', ['unpaid', 'partial']);
            }
            
            // PERBAIKAN: Gunakan profit_date_only (Tipe DATE) alih-alih profit_date (DATETIME)
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
                        $baseDate  = \Carbon\Carbon::parse($request->input('date', now()->toDateString()));
                        $startDate = $baseDate->copy()->startOfWeek()->toDateString();
                        $endDate   = $baseDate->copy()->endOfWeek()->toDateString();
                    }

                    $query->whereBetween('profit_date_only', [$startDate, $endDate]);
                    $title = 'Laporan Laba Mingguan - '
                        . \Carbon\Carbon::parse($startDate)->format('d/m/Y')
                        . ' s/d '
                        . \Carbon\Carbon::parse($endDate)->format('d/m/Y');
                    break;
                case 'monthly':
                    $month = $request->input('month', now()->month);
                    $year = $request->input('year', now()->year);
                    $startOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
                    $endOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();
                    $query->whereBetween('profit_date_only', [$startOfMonth, $endOfMonth]);
                    $title = 'Laporan Laba Bulanan - ' . \Carbon\Carbon::createFromDate($year, $month, 1)->format('F Y');
                    break;
                case 'custom':
                    $query->whereBetween('profit_date_only', [$request->start_date, $request->end_date]);
                    $title = 'Laporan Laba - ' . $request->start_date . ' s/d ' . $request->end_date;
                    break;
            }
            
            $summaryQuery = clone $query;
            
            // Generate chart data BEFORE getting the collection for pagination
            if ($request->period !== 'daily') {
                $chartStartDate = $startDate ?? ($startOfMonth ?? ($request->start_date ?? null));
                $chartEndDate = $endDate ?? ($endOfMonth ?? ($request->end_date ?? null));
                
                $chartData = $this->getProfitChartData($request->period, $request, clone $summaryQuery, $category, $chartStartDate, $chartEndDate);
            } else {
                $chartData = [];
            }
            
            $profits = $query->orderBy('profit_date', 'desc')->orderBy('id', 'desc')->paginate($request->input('per_page', 10));
            
            // Format profits
            $formattedProfits = $profits->getCollection()->map(function ($profit) {
                $isFromReceivable = (bool) $profit->is_from_receivable;
                $receivableStatus = $profit->receivable_status;
                $profitStatus = (!$isFromReceivable || $receivableStatus === 'paid')
                    ? 'realized'
                    : 'pending';

                $detail = $this->profitService->getTransactionDetail($profit);

                $totalPenjualan = $detail ? (float) ($detail->price * $profit->quantity_sold) : 0.0;
                $totalModal = $detail ? (float) ($detail->purchase_price * $profit->quantity_sold) : 0.0;

                return [
                    'id' => $profit->id,
                    'transaction_id' => $profit->transaction_id,
                    'invoice_number' => $profit->transaction->invoice_number ?? '-',
                    'product_id' => $profit->product_id,
                    'product_name' => $profit->product->name ?? '-',
                    'product_category' => $profit->product->category ?? '-',
                    'quantity_sold' => $profit->quantity_sold,
                    'profit_amount' => (float) $profit->profit_amount,
                    'profit_amount_formatted' => 'Rp ' . number_format($profit->profit_amount, 0, ',', '.'),
                    'profit_date' => $profit->profit_date,
                    'profit_date_formatted' => date('d/m/Y', strtotime($profit->profit_date)),
                    'profit_status' => $profitStatus,
                    'total_penjualan' => $totalPenjualan,
                    'total_penjualan_formatted' => 'Rp ' . number_format($totalPenjualan, 0, ',', '.'),
                    'total_modal' => $totalModal,
                    'total_modal_formatted' => 'Rp ' . number_format($totalModal, 0, ',', '.'),
                ];
            });
            $profits->setCollection($formattedProfits);
            
            $allProfitsForSummary = (clone $summaryQuery)->with('transaction.details')->get();

            $totalPendapatanKotor = $this->profitService->calculateTotalOmzet($allProfitsForSummary);
            $totalBeban = $this->profitService->calculateTotalBeban($allProfitsForSummary);

            $summary = [
                'total_profit' => (float) $summaryQuery->sum('profit_amount'),
                'total_profit_formatted' => 'Rp ' . number_format($summaryQuery->sum('profit_amount'), 0, ',', '.'),

                'realized_profit' => (float) (clone $summaryQuery)
                    ->where(function($q){
                        $q->where('is_from_receivable', false)
                          ->orWhere('receivable_status', 'paid');
                    })
                    ->sum('profit_amount'),
                'realized_profit_formatted' => 'Rp ' . number_format((clone $summaryQuery)
                    ->where(function($q){
                        $q->where('is_from_receivable', false)
                          ->orWhere('receivable_status', 'paid');
                    })
                    ->sum('profit_amount'), 0, ',', '.'),

                'pending_profit' => (float) (clone $summaryQuery)
                    ->where('is_from_receivable', true)
                    ->whereIn('receivable_status', ['unpaid', 'partial'])
                    ->sum('profit_amount'),
                'pending_profit_formatted' => 'Rp ' . number_format((clone $summaryQuery)
                    ->where('is_from_receivable', true)
                    ->whereIn('receivable_status', ['unpaid', 'partial'])
                    ->sum('profit_amount'), 0, ',', '.'),
                    
                'total_pendapatan_kotor' => $totalPendapatanKotor,
                'total_pendapatan_kotor_formatted' => 'Rp ' . number_format($totalPendapatanKotor, 0, ',', '.'),
                'total_beban' => $totalBeban,
                'total_beban_formatted' => 'Rp ' . number_format($totalBeban, 0, ',', '.'),
            ];
            
            return $this->success([
                'title' => $title,
                'generated_at' => now()->format('d/m/Y H:i:s'),
                'summary' => $summary,
                'profits' => $profits,
                'chart' => $chartData,
            ], 'Laporan laba berhasil dimuat', 200);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Profit report error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat laporan laba: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Laporan Barang Rusak
     */public function badProductReport(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $supplierId = $request->input('supplier_id');
            
            $query = BadProduct::with(['product', 'product.supplier', 'reportedBy']);
            
            if ($supplierId) {
                $query->whereHas('product', function ($q) use ($supplierId) {
                    $q->where('supplier_id', $supplierId);
                });
            }
            
            $badProducts = $query->orderBy('incident_date', 'desc')->paginate($perPage);
            
            $formattedBadProducts = $badProducts->getCollection()->map(function ($item) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name ?? '-',
                    'product_category' => $item->product->category ?? '-',
                    'quantity' => $item->quantity,
                    'unit' => $item->unit ?? $item->product->unit ?? '-',
                    'damage_reason' => $item->damage_reason,
                    'loss_amount' => (float) $item->loss_amount,
                    'loss_amount_formatted' => 'Rp ' . number_format($item->loss_amount, 0, ',', '.'),
                    'incident_date' => $item->incident_date,
                    'incident_date_formatted' => date('d/m/Y', strtotime($item->incident_date)),
                    'reported_to_supplier' => $item->reported_to_supplier,
                    'reported_status' => $item->reported_to_supplier ? 'Sudah Dilapor' : 'Belum Dilapor',
                    'supplier_name' => $item->product->supplier->name ?? '-',
                    'reported_by_name' => $item->reportedBy->name ?? '-',
                ];
            });
            $badProducts->setCollection($formattedBadProducts);
            
            $summary = [
                'total_quantity' => BadProduct::whereMonth('incident_date', now()->month)->sum('quantity'),
                'total_loss' => (float) BadProduct::whereMonth('incident_date', now()->month)->sum('loss_amount'),
                'total_loss_formatted' => 'Rp ' . number_format(BadProduct::whereMonth('incident_date', now()->month)->sum('loss_amount'), 0, ',', '.'),
                'reported_count' => BadProduct::where('reported_to_supplier', true)->count(),
                'unreported_count' => BadProduct::where('reported_to_supplier', false)->count(),
            ];
            
            return $this->success([
                'title' => 'Laporan Barang Rusak',
                'generated_at' => now()->format('d/m/Y H:i:s'),
                'summary' => $summary,
                'bad_products' => $badProducts,
            ], 'Laporan barang rusak berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Bad product report error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat laporan barang rusak: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Laporan Piutang
     */
    public function receivableReport(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $status = $request->input('status');
            
            $query = Receivable::with(['transaction.cashier']);
            
            if ($status && in_array($status, ['unpaid', 'partial', 'paid'])) {
                $query->where('status', $status);
            }
            
            $receivables = $query->orderBy('due_date', 'asc')->paginate($perPage);
            
            $formattedReceivables = $receivables->getCollection()->map(function ($item) {
                $dueDate = \Carbon\Carbon::parse($item->due_date);
                $isOverdue = $dueDate->isPast() && $item->status !== 'paid';
                
                return [
                    'id' => $item->id,
                    'transaction_id' => $item->transaction_id,
                    'invoice_number' => $item->transaction->invoice_number ?? '-',
                    'customer_name' => $item->customer_name,
                    'customer_phone' => $item->customer_phone,
                    'customer_address' => $item->customer_address,
                    'total_debt' => (float) $item->total_debt,
                    'total_debt_formatted' => 'Rp ' . number_format($item->total_debt, 0, ',', '.'),
                    'paid_amount' => (float) $item->paid_amount,
                    'paid_amount_formatted' => 'Rp ' . number_format($item->paid_amount, 0, ',', '.'),
                    'remaining_debt' => (float) $item->remaining_debt,
                    'remaining_debt_formatted' => 'Rp ' . number_format($item->remaining_debt, 0, ',', '.'),
                    'due_date' => $item->due_date,
                    'due_date_formatted' => $dueDate->format('d/m/Y'),
                    'is_overdue' => $isOverdue,
                    'status' => $item->status,
                    'status_label' => $this->getReceivableStatusLabel($item->status),
                    'cashier_name' => $item->transaction->cashier->name ?? '-',
                    'created_at' => $item->created_at,
                    'created_at_formatted' => $item->created_at->format('d/m/Y'),
                ];
            });
            $receivables->setCollection($formattedReceivables);
            
            $summary = [
                'total_receivable' => (float) Receivable::where('status', '!=', 'paid')->sum('remaining_debt'),
                'total_receivable_formatted' => 'Rp ' . number_format(Receivable::where('status', '!=', 'paid')->sum('remaining_debt'), 0, ',', '.'),
                'overdue_amount' => (float) Receivable::where('due_date', '<', now())
                    ->where('status', '!=', 'paid')
                    ->sum('remaining_debt'),
                'overdue_amount_formatted' => 'Rp ' . number_format(Receivable::where('due_date', '<', now())
                    ->where('status', '!=', 'paid')
                    ->sum('remaining_debt'), 0, ',', '.'),
                'total_customers' => Receivable::distinct('customer_name')->count('customer_name'),
                'overdue_count' => Receivable::where('due_date', '<', now())
                    ->where('status', '!=', 'paid')
                    ->count(),
            ];
            
            return $this->success([
                'title' => 'Laporan Piutang Pelanggan',
                'generated_at' => now()->format('d/m/Y H:i:s'),
                'summary' => $summary,
                'receivables' => $receivables,
            ], 'Laporan piutang berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Receivable report error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat laporan piutang: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Export PDF Laporan Penjualan
     */
    public function exportSalesPDF(Request $request)
    {
        try {
            $data = $this->getSalesReportData($request);
            $pdf = Pdf::loadView('reports.sales-pdf', $data);
            $pdf->setPaper('A4', 'landscape');
            
            $filename = 'laporan_penjualan_' . date('Ymd_His') . '.pdf';
            
            return $pdf->download($filename);
            
        } catch (\Exception $e) {
            $this->logger->error('Export PDF error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengexport PDF: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Export Excel Laporan Penjualan
     */
    public function exportSalesExcel(Request $request)
    {
        try {
            $data = $this->getSalesReportData($request);

            $filename = 'laporan_penjualan_' . date('Ymd_His') . '.xlsx';

            $this->logger->info('Admin mengexport laporan penjualan ke Excel');

            return Excel::download(new SalesExport($data), $filename);

        } catch (\Exception $e) {
            $this->logger->error('Export Excel error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengexport Excel', null, 500);
        }
    }

    /**
     * Get data for sales report (used by PDF export)
     */
    protected function getSalesReportData(Request $request)
    {
        // Include valid payment statuses
        $query = Transaction::with(['cashier', 'details.product'])
            ->whereIn('payment_status', ['paid', 'partial', 'unpaid', 'pending']);

        if ($request->period === 'custom' && $request->start_date && $request->end_date) {
            $query->whereBetween('tx_date', [$request->start_date, $request->end_date]);
        } elseif ($request->period === 'daily') {
            $query->where('tx_date', $request->input('date', now()->toDateString()));
        } elseif ($request->period === 'weekly') {
            $baseDate = \Carbon\Carbon::parse($request->input('date', now()->toDateString()));
            $query->whereBetween('tx_date', [$baseDate->copy()->startOfWeek()->toDateString(), $baseDate->copy()->endOfWeek()->toDateString()]);
        } elseif ($request->period === 'monthly') {
            $month = $request->input('month', now()->month);
            $year = $request->input('year', now()->year);
            $startOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
            $endOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();
            $query->whereBetween('tx_date', [$startOfMonth, $endOfMonth]);
        }
        
        $transactions = $query->orderBy('created_at', 'desc')->get();
        
        $summary = [
            'total_transactions' => $transactions->count(),
            'total_revenue' => $transactions->sum('total_amount'),
            'total_revenue_formatted' => 'Rp ' . number_format($transactions->sum('total_amount'), 0, ',', '.'),
        ];
        
        return [
            'title' => 'Laporan Penjualan Grosir Tiga Bersaudara',
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'summary' => $summary,
            'transactions' => $transactions,
        ];
    }

    protected function getSalesChartData($period, Request $request, $baseQuery = null, $category = null, $startDate = null, $endDate = null)
    {
        $chartData = [];
        $labels = [];
        $data = [];

        if (!$baseQuery) {
            $baseQuery = \App\Models\Transaction::whereIn('payment_status', ['paid', 'partial', 'unpaid', 'pending']);
        }
        
        $periodDates = [];
        
        if ($period === 'weekly') {
            if ($startDate && $endDate) {
                $periodDates = \Carbon\CarbonPeriod::create($startDate, $endDate);
            } else {
                $baseDate = \Carbon\Carbon::parse($request?->input('date', now()->toDateString()) ?? now()->toDateString());
                $periodDates = \Carbon\CarbonPeriod::create($baseDate->copy()->startOfWeek(), $baseDate->copy()->endOfWeek());
            }
        } elseif ($period === 'monthly') {
            if ($startDate && $endDate) {
                $periodDates = \Carbon\CarbonPeriod::create($startDate, $endDate);
            } else {
                $month = $request?->input('month', now()->month) ?? now()->month;
                $year = $request?->input('year', now()->year) ?? now()->year;
                $periodDates = \Carbon\CarbonPeriod::create(
                    \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth(),
                    \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth()
                );
            }
        } elseif ($period === 'custom') {
            $start = \Carbon\Carbon::parse($request?->input('start_date') ?? now()->toDateString());
            $end = \Carbon\Carbon::parse($request?->input('end_date') ?? now()->toDateString());
            $periodDates = \Carbon\CarbonPeriod::create($start, $end);
        }

        foreach ($periodDates as $carbonDate) {
            $dateStr = $carbonDate->toDateString();
            $labels[] = $carbonDate->format('d/m');
            
            $dayQuery = (clone $baseQuery)->where('tx_date', $dateStr);
            $daySummary = $this->calculateSalesSummary($dayQuery, $category);
            
            $data[] = (int) $daySummary['total_omzet_kotor'];
        }

        return [
            'labels' => $labels,
            'sales' => $data,
            'scale' => [
                'min' => 0,
                'max' => count($data) > 0 && max($data) >= 1000000 ? (int) max($data) : 1000000,
            ],
        ];
    }

    /**
     * Get chart data for profit report
     */
    protected function getProfitChartData($period, Request $request, $baseQuery = null, $category = null, $startDate = null, $endDate = null)
    {
        $chartData = [];
        
        if (!$baseQuery) {
            $baseQuery = \App\Models\Profit::with(['product', 'transaction.details'])
                ->whereHas('transaction', function ($q) {
                    $q->whereIn('payment_status', ['paid', 'partial', 'unpaid', 'pending']);
                });
        }
        
        $periodDates = [];
        
        if ($period === 'weekly') {
            if ($startDate && $endDate) {
                $periodDates = \Carbon\CarbonPeriod::create($startDate, $endDate);
            } else {
                $baseDate = \Carbon\Carbon::parse($request->input('date', now()->toDateString()));
                $periodDates = \Carbon\CarbonPeriod::create($baseDate->copy()->startOfWeek(), $baseDate->copy()->endOfWeek());
            }
        } elseif ($period === 'monthly') {
            if ($startDate && $endDate) {
                $periodDates = \Carbon\CarbonPeriod::create($startDate, $endDate);
            } else {
                $month = $request->input('month', now()->month);
                $year = $request->input('year', now()->year);
                $periodDates = \Carbon\CarbonPeriod::create(
                    \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth(),
                    \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth()
                );
            }
        } elseif ($period === 'custom') {
            $start = \Carbon\Carbon::parse($request->input('start_date') ?? now()->toDateString());
            $end = \Carbon\Carbon::parse($request->input('end_date') ?? now()->toDateString());
            $periodDates = \Carbon\CarbonPeriod::create($start, $end);
        }

        foreach ($periodDates as $carbonDate) {
            $dateStr = $carbonDate->toDateString();
            $dayQuery = (clone $baseQuery)->where('profit_date_only', $dateStr);
            $profit = (float) $dayQuery->sum('profit_amount');
            
            $chartData[] = [
                'date' => $dateStr,
                'day' => ($period === 'monthly') ? $carbonDate->format('j') : $carbonDate->format('d/m'),
                'day_short' => $carbonDate->format('D'),
                'profit' => $profit,
                'profit_formatted' => 'Rp ' . number_format($profit, 0, ',', '.'),
            ];
        }
        
        return $chartData;
    }

    /**
     * Get unit label in Indonesian
     */
    private function getUnitLabel($unit)
    {
        $labels = [
            'tray' => 'Tray (30 butir)',
            'butir' => 'Butir',
            'kg' => 'Kilogram',
            'karung' => 'Karung (50 kg)',
        ];
        return $labels[$unit] ?? $unit;
    }

    /**
     * Get payment method label in Indonesian
     */
    private function getPaymentMethodLabel($method)
    {
        $labels = [
            'cash' => 'Tunai',
            'transfer' => 'Transfer Bank',
            'qris' => 'QRIS',
            'receivable' => 'Hutang',
        ];
        return $labels[$method] ?? $method;
    }

    /**
     * Get payment status label in Indonesian
     */
    private function getPaymentStatusLabel($status)
    {
        $labels = [
            'paid' => 'Lunas',
            'unpaid' => 'Belum Lunas',
            'partial' => 'Sebagian',
        ];
        return $labels[$status] ?? $status;
    }

    /**
     * Get receivable status label in Indonesian
     */
    private function getReceivableStatusLabel($status)
    {
        $labels = [
            'unpaid' => 'Belum Dibayar',
            'partial' => 'Sebagian',
            'paid' => 'Lunas',
        ];
        return $labels[$status] ?? $status;
    }

    // ==================== EXPORT METHODS ====================

    /**
     * Export Stock Report to PDF
     */
    public function exportStockPDF(Request $request)
    {
        try {
            $data = $this->getStockReportData($request);
            $pdf = Pdf::loadView('reports.stock-pdf', $data);
            $pdf->setPaper('A4', 'landscape');

            $filename = 'laporan_stok_' . date('Ymd_His') . '.pdf';

            return $pdf->download($filename);

        } catch (\Exception $e) {
            $this->logger->error('Export Stock PDF error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengexport PDF: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Export Stock Report to Excel
     */
    public function exportStockExcel(Request $request)
    {
        try {
            $data = $this->getStockReportData($request);
            $filename = 'laporan_stok_' . date('Ymd_His') . '.xlsx';

            return Excel::download(new StockExport($data), $filename);

        } catch (\Exception $e) {
            $this->logger->error('Export Stock Excel error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengexport Excel', null, 500);
        }
    }

    protected function getStockReportData(Request $request)
    {
        $category = $request->input('category');
        $query = Product::with('supplier');

        if ($category && in_array($category, ['egg', 'rice'])) {
            $query->where('category', $category);
        }

        $products = $query->orderBy('name')->get();

        $formattedProducts = $products->map(function ($product) {
            return [
                'name' => $product->name,
                'category' => $product->category,
                'category_label' => $product->category == 'egg' ? 'Telur' : 'Beras',
                'unit' => $product->unit,
                'stock' => $product->stock,
                'min_stock' => $product->min_stock,
                'purchase_price' => (float) $product->purchase_price,
                'selling_price' => (float) $product->selling_price,
                'status' => $product->stock <= $product->min_stock ? 'Hampir Habis' : 'Aman',
            ];
        });

        return [
            'title' => 'Laporan Stok Barang Grosir Tiga Bersaudara',
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'products' => $formattedProducts->toArray(),
        ];
    }

    /**
     * Export Bad Product Report to PDF
     */
    public function exportBadProductPDF(Request $request)
    {
        try {
            $data = $this->getBadProductReportData($request);
            $pdf = Pdf::loadView('reports.bad-product-pdf', $data);
            $pdf->setPaper('A4', 'landscape');

            $filename = 'laporan_barang_rusak_' . date('Ymd_His') . '.pdf';

            return $pdf->download($filename);

        } catch (\Exception $e) {
            $this->logger->error('Export Bad Product PDF error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengexport PDF: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Export Bad Product Report to Excel
     */
    public function exportBadProductExcel(Request $request)
    {
        try {
            $data = $this->getBadProductReportData($request);
            $filename = 'laporan_barang_rusak_' . date('Ymd_His') . '.xlsx';

            return Excel::download(new BadProductExport($data), $filename);

        } catch (\Exception $e) {
            $this->logger->error('Export Bad Product Excel error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengexport Excel', null, 500);
        }
    }

    protected function getBadProductReportData(Request $request)
    {
        $query = BadProduct::with(['product', 'product.supplier', 'reportedBy']);
        $badProducts = $query->orderBy('incident_date', 'desc')->get();

        $formatted = $badProducts->map(function ($item) {
            return [
                'product_name' => $item->product->name ?? '-',
                'product_category' => $item->product->category ?? '-',
                'quantity' => $item->quantity,
                'unit' => $item->unit ?? $item->product->unit ?? '-',
                'damage_reason' => $item->damage_reason,
                'loss_amount' => (float) $item->loss_amount,
                'incident_date_formatted' => date('d/m/Y', strtotime($item->incident_date)),
                'reported_status' => $item->reported_to_supplier ? 'Sudah Dilapor' : 'Belum Dilapor',
            ];
        });

        return [
            'title' => 'Laporan Barang Rusak Grosir Tiga Bersaudara',
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'bad_products' => $formatted->toArray(),
        ];
    }

    /**
     * Export Receivable Report to PDF
     */
    public function exportReceivablePDF(Request $request)
    {
        try {
            $data = $this->getReceivableReportData($request);
            $pdf = Pdf::loadView('reports.receivable-pdf', $data);
            $pdf->setPaper('A4', 'landscape');

            $filename = 'laporan_piutang_' . date('Ymd_His') . '.pdf';

            return $pdf->download($filename);

        } catch (\Exception $e) {
            $this->logger->error('Export Receivable PDF error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengexport PDF: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Export Receivable Report to Excel
     */
    public function exportReceivableExcel(Request $request)
    {
        try {
            $data = $this->getReceivableReportData($request);
            $filename = 'laporan_piutang_' . date('Ymd_His') . '.xlsx';

            return Excel::download(new ReceivableExport($data), $filename);

        } catch (\Exception $e) {
            $this->logger->error('Export Receivable Excel error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengexport Excel', null, 500);
        }
    }

    protected function getReceivableReportData(Request $request)
    {
        $status = $request->input('status');
        $query = Receivable::with(['transaction.cashier']);

        if ($status && in_array($status, ['unpaid', 'partial', 'paid'])) {
            $query->where('status', $status);
        }

        $receivables = $query->orderBy('due_date', 'asc')->get();

        $formatted = $receivables->map(function ($item) {
            $dueDate = \Carbon\Carbon::parse($item->due_date);
            return [
                'invoice_number' => $item->transaction->invoice_number ?? '-',
                'customer_name' => $item->customer_name,
                'customer_phone' => $item->customer_phone ?? '-',
                'total_debt' => (float) $item->total_debt,
                'paid_amount' => (float) $item->paid_amount,
                'remaining_debt' => (float) $item->remaining_debt,
                'due_date_formatted' => $dueDate->format('d/m/Y'),
                'status' => $item->status,
                'status_label' => $this->getReceivableStatusLabel($item->status),
            ];
        });

        return [
            'title' => 'Laporan Piutang Pelanggan Grosir Tiga Bersaudara',
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'receivables' => $formatted->toArray(),
        ];
    }

    /**
     * Export Profit Report to PDF
     */
    public function exportProfitPDF(Request $request)
    {
        try {
            $data = $this->getProfitReportData($request);
            $pdf = Pdf::loadView('reports.profit-pdf', $data);
            $pdf->setPaper('A4', 'landscape');

            $filename = 'laporan_laba_' . date('Ymd_His') . '.pdf';

            return $pdf->download($filename);

        } catch (\Exception $e) {
            $this->logger->error('Export Profit PDF error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengexport PDF: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Export Profit Report to Excel
     */
    public function exportProfitExcel(Request $request)
    {
        try {
            $data = $this->getProfitReportData($request);
            $filename = 'laporan_laba_' . date('Ymd_His') . '.xlsx';

            return Excel::download(new ProfitExport($data), $filename);

        } catch (\Exception $e) {
            $this->logger->error('Export Profit Excel error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengexport Excel', null, 500);
        }
    }

    protected function getProfitReportData(Request $request)
    {
        $query = Profit::with(['product', 'transaction'])
            ->whereHas('transaction', function ($q) {
                $q->whereIn('payment_status', ['paid', 'partial', 'unpaid', 'pending']);
            });

        switch ($request->period) {
            case 'daily':
                $date = $request->input('date', now()->toDateString());
                $query->where('profit_date_only', $date);
                break;
            case 'weekly':
                $baseDate = \Carbon\Carbon::parse($request->input('date', now()->toDateString()));
                $query->whereBetween('profit_date_only', [
                    $baseDate->copy()->startOfWeek()->toDateString(), 
                    $baseDate->copy()->endOfWeek()->toDateString()
                ]);
                break;
            case 'monthly':
                $month = $request->input('month', now()->month);
                $year = $request->input('year', now()->year);
                $startOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
                $endOfMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();
                $query->whereBetween('profit_date_only', [$startOfMonth, $endOfMonth]);
                break;
            case 'custom':
                if ($request->start_date && $request->end_date) {
                    $query->whereBetween('profit_date_only', [$request->start_date, $request->end_date]);
                }
                break;
        }

        $profits = $query->orderBy('profit_date', 'desc')->get();

        // Group by date for summary view
        $grouped = $profits->groupBy('profit_date')->map(function ($items, $date) {
            $totalOmzet = $items->sum(function ($p) {
                return $p->quantity_sold * $p->product->selling_price;
            });
            $totalModal = $items->sum(function ($p) {
                return $p->quantity_sold * $p->product->purchase_price;
            });
            $totalProfit = $items->sum('profit_amount');

            $realizedProfit = $items->sum(function ($p) {
                if (!$p->is_from_receivable || $p->receivable_status === 'paid') {
                    return $p->profit_amount;
                }
                return 0;
            });

            $pendingProfit = $items->sum(function ($p) {
                if ($p->is_from_receivable && in_array($p->receivable_status, ['unpaid', 'partial'])) {
                    return $p->profit_amount;
                }
                return 0;
            });

            return [
                'profit_date' => $date,
                'omzet_jual' => (float) $totalOmzet,
                'modal_barang' => (float) $totalModal,
                'profit_amount' => (float) $totalProfit,
                'realized_profit' => (float) $realizedProfit,
                'pending_profit' => (float) $pendingProfit,
            ];
        })->values();

        $summary = [
            'total_profit' => $grouped->sum('profit_amount'),
            'realized_profit' => $grouped->sum('realized_profit'),
            'pending_profit' => $grouped->sum('pending_profit'),
        ];

        $profitsDetail = $profits->map(function ($p) {
            $isPending = $p->is_from_receivable && in_array($p->receivable_status, ['unpaid', 'partial']);
            return [
                'date' => date('d/m/Y', strtotime($p->profit_date)),
                'invoice_number' => $p->transaction->invoice_number ?? '-',
                'product_name' => $p->product->name ?? '-',
                'qty' => $p->quantity_sold,
                'profit' => (float) $p->profit_amount,
                'status_label' => $isPending ? 'Tertunda' : 'Terealisasi'
            ];
        });

        return [
            'title' => 'Laporan Laba Bersih Grosir Tiga Bersaudara',
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'profits' => $grouped->toArray(),
            'profits_detail' => $profitsDetail->toArray(),
            'summary' => $summary,
        ];
    }
}
