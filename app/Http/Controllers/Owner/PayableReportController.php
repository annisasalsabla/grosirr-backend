<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Payable;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;

class PayableReportController extends Controller
{
    use ApiResponseTrait;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $status = $request->input('status');

            $query = Payable::with(['supplier', 'supplier.products']);

            if ($status && in_array($status, ['unpaid', 'partial', 'paid', 'overdue'])) {
                if ($status === 'overdue') {
                    $query->where('due_date', '<', now())->where('status', '!=', 'paid');
                } else {
                    $query->where('status', $status);
                }
            }

            $payables = $query->orderBy('due_date', 'asc')->paginate($perPage);

            // Summary Total Hutang
            $summary = [
                'total_payable' => Payable::where('status', '!=', 'paid')->sum('remaining_debt'),
                'total_payable_formatted' => 'Rp ' . number_format(Payable::where('status', '!=', 'paid')->sum('remaining_debt'), 0, ',', '.'),
                'total_paid' => Payable::where('status', 'paid')->sum('total_debt'),
                'overdue_amount' => Payable::where('due_date', '<', now())->where('status', '!=', 'paid')->sum('remaining_debt'),
                'total_suppliers' => Payable::distinct('supplier_id')->count('supplier_id'),
                'overdue_count' => Payable::where('due_date', '<', now())->where('status', '!=', 'paid')->count(),
            ];

            // List supplier dengan hutang aktif
            $supplierList = Payable::where('status', '!=', 'paid')
                ->with('supplier')
                ->select('supplier_id')
                ->selectRaw('SUM(remaining_debt) as total_debt')
                ->selectRaw('COUNT(*) as total_transactions')
                ->groupBy('supplier_id')
                ->orderByDesc('total_debt')
                ->get()
                ->map(function ($item) {
                    return [
                        'supplier_id' => $item->supplier_id,
                        'supplier_name' => $item->supplier->name ?? 'Unknown',
                        'total_debt' => (float) $item->total_debt,
                        'total_debt_formatted' => 'Rp ' . number_format($item->total_debt, 0, ',', '.'),
                        'total_transactions' => $item->total_transactions,
                    ];
                });

            return $this->success([
                'payables' => $payables,
                'summary' => $summary,
                'by_supplier' => $supplierList
            ], 'Laporan hutang berhasil dimuat', 200);

        } catch (\Exception $e) {
            $this->logger->error('Payable report error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat laporan hutang', null, 500);
        }
    }
}