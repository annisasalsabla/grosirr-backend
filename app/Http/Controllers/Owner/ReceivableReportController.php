<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Receivable;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;

class ReceivableReportController extends Controller
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

            $query = Receivable::with('transaction.cashier');

            if ($status && in_array($status, ['unpaid', 'partial', 'paid', 'overdue'])) {
                if ($status === 'overdue') {
                    $query->where('due_date', '<', now())->where('status', '!=', 'paid');
                } else {
                    $query->where('status', $status);
                }
            }

            $receivables = $query->orderBy('due_date', 'asc')->paginate($perPage);

            // Summary Total Piutang
            $summary = [
                'total_receivable' => Receivable::where('status', '!=', 'paid')->sum('remaining_debt'),
                'total_receivable_formatted' => 'Rp ' . number_format(Receivable::where('status', '!=', 'paid')->sum('remaining_debt'), 0, ',', '.'),
                'total_paid' => Receivable::where('status', 'paid')->sum('total_debt'),
                'overdue_amount' => Receivable::where('due_date', '<', now())->where('status', '!=', 'paid')->sum('remaining_debt'),
                'total_customers' => Receivable::distinct('customer_name')->count('customer_name'),
                'overdue_count' => Receivable::where('due_date', '<', now())->where('status', '!=', 'paid')->count(),
                'collection_rate' => $this->calculateCollectionRate(),
            ];

            // List customer dengan piutang aktif
            $customerList = Receivable::where('status', '!=', 'paid')
                ->select('customer_name', 'customer_phone')
                ->selectRaw('SUM(remaining_debt) as total_debt')
                ->selectRaw('COUNT(*) as total_transactions')
                ->groupBy('customer_name', 'customer_phone')
                ->orderByDesc('total_debt')
                ->get();

            return $this->success([
                'receivables' => $receivables,
                'summary' => $summary,
                'by_customer' => $customerList
            ], 'Laporan piutang berhasil dimuat', 200);

        } catch (\Exception $e) {
            $this->logger->error('Receivable report error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat laporan piutang', null, 500);
        }
    }

    private function calculateCollectionRate()
    {
        $totalReceivable = Receivable::sum('total_debt');
        $totalPaid = Receivable::sum('paid_amount');
        
        if ($totalReceivable <= 0) return 100;
        
        return round(($totalPaid / $totalReceivable) * 100, 2);
    }
}