<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\BadProduct;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TransactionController extends Controller
{
    use ApiResponseTrait;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Get all transactions with filters
     * GET /api/admin/transactions
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $status = $request->input('status');
            $paymentMethod = $request->input('payment_method');
            $date = $request->input('date');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            $search = $request->input('search');

            $query = Transaction::with(['cashier', 'details.product', 'customer']);

            // Filter by payment_status
            if ($status && in_array($status, ['paid', 'partial', 'pending', 'unpaid'])) {
                $query->where('payment_status', $status);
            } else {
                // Default: Tampilkan semua transaksi valid (kecuali failed/cancelled)
                $query->validSales();
            }

            // Filter by payment method
            if ($paymentMethod && in_array($paymentMethod, ['cash', 'transfer', 'qris', 'qris_biasa', 'midtrans_qris', 'receivable'])) {
                $query->where('payment_method', $paymentMethod);
            }

            // Filter by single date OR date range
            if ($date) {
                $query->whereDate('created_at', Carbon::parse($date)->toDateString());
            } elseif ($startDate && $endDate) {
                $query->whereBetween('created_at', [
                    Carbon::parse($startDate)->startOfDay(),
                    Carbon::parse($endDate)->endOfDay()
                ]);
            }

            // Search by invoice number or customer name
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('invoice_number', 'like', '%' . $search . '%')
                      ->orWhereHas('customer', function ($cq) use ($search) {
                          $cq->where('name', 'like', '%' . $search . '%')
                             ->orWhere('phone', 'like', '%' . $search . '%');
                      });
                });
            }

            $transactions = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate($perPage);

            $formattedTransactions = $transactions->getCollection()->map(function ($transaction) {
                $items = [];
                $totalItems = 0;

                foreach ($transaction->details as $detail) {
                    $items[] = [
                        'product_id' => $detail->product_id,
                        'product_name' => $detail->product->name,
                        'quantity' => $detail->quantity,
                        'unit' => $detail->product->unit,
                        'price' => (float) $detail->price,
                        'subtotal' => (float) $detail->subtotal,
                    ];
                    $totalItems += $detail->quantity;
                }

                $remainingBalance = max(0, $transaction->total_amount - $transaction->paid_amount);

                return [
                    'id' => $transaction->id,
                    'invoice_number' => $transaction->invoice_number,
                    'created_at' => $transaction->created_at->format('Y-m-d H:i:s'),
                    'tanggal' => $transaction->created_at->format('d/m/Y'),
                    'waktu' => $transaction->created_at->format('H:i:s'),
                    'cashier_name' => $transaction->cashier->name ?? '-',
                    'customer_name' => $transaction->customer->name ?? '-',
                    'payment_method' => $transaction->payment_method,
                    'payment_method_label' => $this->getPaymentMethodLabel($transaction->payment_method),
                    'payment_status' => $transaction->payment_status,
                    'payment_status_label' => $this->getPaymentStatusLabel($transaction->payment_status),
                    'total_amount' => (float) $transaction->total_amount,
                    'total_amount_formatted' => 'Rp ' . number_format($transaction->total_amount, 0, ',', '.'),
                    'paid_amount' => (float) $transaction->paid_amount,
                    'paid_amount_formatted' => 'Rp ' . number_format($transaction->paid_amount, 0, ',', '.'),
                    'remaining_balance' => (float) $remainingBalance,
                    'remaining_balance_formatted' => 'Rp ' . number_format($remainingBalance, 0, ',', '.'),
                    'items' => $items,
                    'total_items' => $totalItems,
                    'bukti_pembayaran_url' => $transaction->bukti_pembayaran_url,
                ];
            });

            $transactions->setCollection($formattedTransactions);

            $summary = [
                'total_transactions' => (clone $query)->count(),
                'total_omzet' => (float) (clone $query)->sum('total_amount'),
                'total_kas_diterima' => (float) (clone $query)->sum(\Illuminate\Support\Facades\DB::raw('CASE WHEN paid_amount > total_amount THEN total_amount ELSE paid_amount END')),
                'paid_count' => (clone $query)->where('payment_status', 'paid')->count(),
                'partial_count' => (clone $query)->where('payment_status', 'partial')->count(),
                'unpaid_count' => (clone $query)->where('payment_status', 'unpaid')->count(),
                'pending_count' => (clone $query)->where('payment_status', 'pending')->count(),
                'piutang_count' => (clone $query)->whereIn('payment_status', ['unpaid', 'partial'])->count(),
            ];

            return $this->success([
                'transactions' => $transactions,
                'summary' => $summary,
            ], 'Riwayat transaksi berhasil dimuat', 200);

        } catch (\Exception $e) {
            $this->logger->error('Admin transaction index error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat riwayat transaksi', null, 500);
        }
    }

    /**
     * Get transaction details
     * GET /api/admin/transactions/{id}
     */
    public function show($id)
    {
        try {
            $transaction = Transaction::with([
                'cashier',
                'details.product',
                'customer',
                'receivable'
            ])->findOrFail($id);

            $items = [];
            $totalItems = 0;

            foreach ($transaction->details as $detail) {
                $items[] = [
                    'product_id' => $detail->product_id,
                    'product_name' => $detail->product->name,
                    'product_category' => $detail->product->category,
                    'quantity' => $detail->quantity,
                    'unit' => $detail->product->unit,
                    'price' => (float) $detail->price,
                    'subtotal' => (float) $detail->subtotal,
                ];
                $totalItems += $detail->quantity;
            }

            $formattedTransaction = [
                'id' => $transaction->id,
                'invoice_number' => $transaction->invoice_number,
                'created_at' => $transaction->created_at->format('Y-m-d H:i:s'),
                'tanggal' => $transaction->created_at->format('d/m/Y'),
                'waktu' => $transaction->created_at->format('H:i:s'),
                'cashier_id' => $transaction->cashier_id,
                'cashier_name' => $transaction->cashier->name ?? '-',
                'customer_id' => $transaction->customer_id,
                'customer_name' => $transaction->customer->name ?? '-',
                'payment_method' => $transaction->payment_method,
                'payment_method_label' => $this->getPaymentMethodLabel($transaction->payment_method),
                'payment_status' => $transaction->payment_status,
                'payment_status_label' => $this->getPaymentStatusLabel($transaction->payment_status),
                'total_amount' => (float) $transaction->total_amount,
                'paid_amount' => (float) $transaction->paid_amount,
                'change_due' => (float) $transaction->change_due,
                'down_payment_amount' => (float) $transaction->down_payment_amount,
                'remaining_balance' => (float) $transaction->remaining_balance,
                'due_date' => $transaction->due_date ? $transaction->due_date->format('Y-m-d') : null,
                'items' => $items,
                'total_items' => $totalItems,
                'bukti_pembayaran_url' => $transaction->bukti_pembayaran_url,
            ];

            // Add receivable info if exists
            if ($transaction->receivable) {
                $formattedTransaction['receivable'] = [
                    'customer_name' => $transaction->receivable->customer_name,
                    'customer_phone' => $transaction->receivable->customer_phone,
                    'total_debt' => (float) $transaction->receivable->total_debt,
                    'paid_amount' => (float) $transaction->receivable->paid_amount,
                    'remaining_debt' => (float) $transaction->receivable->remaining_debt,
                    'due_date' => $transaction->receivable->due_date ? $transaction->receivable->due_date->format('Y-m-d') : null,
                    'status' => $transaction->receivable->status,
                ];
            }

            return $this->success($formattedTransaction, 'Detail transaksi berhasil dimuat', 200);

        } catch (\Exception $e) {
            $this->logger->error('Admin transaction show error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat detail transaksi', null, 500);
        }
    }

    /**
     * Cancel/Retur transaction
     * POST /api/admin/transactions/{id}/cancel
     */
    public function cancel(Request $request, $id)
    {
        return $this->error('Fitur pembatalan transaksi telah dinonaktifkan sepenuhnya dari sistem.', null, 403);
    }

    private function getPaymentMethodLabel($method)
    {
        return match($method) {
            'cash' => 'Tunai',
            'transfer' => 'Transfer Bank',
            'qris' => 'QRIS Statis',
            'qris_statis' => 'QRIS Statis',
            'qris_biasa' => 'QRIS Biasa',
            'midtrans_qris' => 'QRIS Midtrans',
            'receivable' => 'Hutang',
            default => $method,
        };
    }

    private function getPaymentStatusLabel($status)
    {
        return match($status) {
            'paid' => 'Lunas',
            'partial' => 'Cicilan',
            'pending' => 'Menunggu',
            'unpaid' => 'Belum Bayar',
            'failed' => 'Gagal',
            'cancelled' => 'Dibatalkan',
            default => $status,
        };
    }
}