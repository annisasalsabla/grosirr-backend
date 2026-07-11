<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use App\Services\WhatsAppService;
use App\Services\MidtransService;
use App\Helpers\CloudinaryHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use App\Models\Setting;

class ReceivableController extends Controller
{
    use ApiResponseTrait;

    protected $logger;
    protected $whatsappService;
    protected $midtransService;

    public function __construct(
        SerenityLoggerService $logger, 
        WhatsAppService $whatsappService,
        MidtransService $midtransService
    ) {
        $this->logger = $logger;
        $this->whatsappService = $whatsappService;
        $this->midtransService = $midtransService;
    }

    /**
     * Display a listing of receivables.
     * GET /api/admin/receivables
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $status = $request->input('status', 'all');
            
            $query = Receivable::with('transaction.cashier');
            
            if ($status !== 'all' && in_array($status, ['unpaid', 'partial', 'paid'])) {
                $query->where('status', $status);
            }
            
            $receivables = $query->orderBy('due_date', 'asc')->orderBy('id', 'asc')->paginate($perPage);
            
            $formattedReceivables = $receivables->getCollection()->map(function ($item) {
                $dueDate = Carbon::parse($item->due_date);
                $now = Carbon::now();
                $isOverdue = $dueDate->isPast() && $item->status !== 'paid';
                // Fitur Monitoring: Deteksi jika jatuh tempo mendekati H-1 (<= 1 hari)
                $isUrgent = !$isOverdue && $item->status !== 'paid' && $now->diffInDays($dueDate, false) <= 1;

                
                return [
                    'id' => $item->id,
                    'transaction_id' => $item->transaction_id,
                    'invoice_number' => $item->transaction->invoice_number ?? '-',
                    'customer_name' => $item->customer_name,
                    'customer_phone' => $item->customer_phone,
                    'total_debt' => (float) $item->total_debt,
                    'total_debt_formatted' => 'Rp ' . number_format($item->total_debt, 0, ',', '.'),
                    'paid_amount' => (float) $item->paid_amount,
                    'paid_amount_formatted' => 'Rp ' . number_format($item->paid_amount, 0, ',', '.'),
                    'remaining_debt' => (float) $item->remaining_debt,
                    'remaining_debt_formatted' => 'Rp ' . number_format($item->remaining_debt, 0, ',', '.'),
                    'due_date' => $item->due_date,
                    'due_date_formatted' => $dueDate->format('d/m/Y'),
                    'is_overdue' => $isOverdue,
                    'is_approaching_due' => $isUrgent, // Flag untuk UI Admin
                    'status' => $item->status,
                    'status_label' => $this->getStatusLabel($item->status),
                    'cashier_name' => $item->transaction->cashier->name ?? '-',
                    'installment_count' => $item->transaction->installment_count ?? 0,
                    'is_installment' => ($item->transaction->down_payment_amount ?? 0) > 0,
                ];
            });
            
            $receivables->setCollection($formattedReceivables);
            
            // Optimalisasi: Ambil nilai sum sekali saja untuk menghindari duplikasi query ke database
            $totalUnpaidAmount = (float) Receivable::where('status', '!=', 'paid')->sum('remaining_debt');
            $totalPaidAmount = (float) Receivable::where('status', 'paid')->sum('total_debt');
            
            $summary = [
                'total_unpaid' => $totalUnpaidAmount,
                'total_unpaid_formatted' => 'Rp ' . number_format($totalUnpaidAmount, 0, ',', '.'),
                'overdue_count' => Receivable::where('due_date', '<', now())
                    ->where('status', '!=', 'paid')
                    ->count(),
                'due_this_week' => Receivable::whereBetween('due_date', [now(), now()->addDays(7)])
                    ->where('status', '!=', 'paid')
                    ->count(),
                'total_paid' => $totalPaidAmount,
                'total_paid_formatted' => 'Rp ' . number_format($totalPaidAmount, 0, ',', '.'),
            ];
            
            return $this->success([
                'receivables' => $receivables,
                'summary' => $summary
            ], 'Daftar piutang berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get receivables error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat daftar piutang', null, 500);
        }
    }

    /**
     * Pay receivable (catat pembayaran cicilan bebas atau lunas) - via CASH
     * POST /api/admin/receivables/{id}/pay
     */
    public function pay($id, Request $request)
    {
        DB::beginTransaction();
        
        try {
            $enabledMethods = [];
            if (Setting::getBool('payment_method_cash', true)) $enabledMethods[] = 'cash';
            if (Setting::getBool('payment_method_transfer', true)) $enabledMethods[] = 'transfer';
            if (Setting::getBool('payment_method_qris', true)) $enabledMethods[] = 'qris_statis';

            $request->validate([
                'payment_amount' => 'required|numeric|min:1',
                'payment_method' => 'required|in:' . implode(',', $enabledMethods),
                'payment_date' => 'nullable|date',
                'bukti_pembayaran' => 'required_if:payment_method,transfer,qris_statis|image|max:2048',
            ], [
                'payment_method.in' => 'Metode pembayaran yang dipilih sedang dinonaktifkan Admin atau tidak valid.',
                'bukti_pembayaran.required_if' => 'Bukti pembayaran wajib diunggah untuk metode Transfer dan QRIS Statis.',
            ]);

            
            $receivable = Receivable::with('transaction')->findOrFail($id);
            $transaction = $receivable->transaction;
            
            if ($receivable->status === 'paid') {
                return $this->error('Piutang ini sudah lunas', null, 400);
            }
            
            if ($request->payment_amount > $receivable->remaining_debt) {
                return $this->error('Jumlah pembayaran melebihi sisa hutang', null, 400);
            }
            
            $newPaidAmount = $receivable->paid_amount + $request->payment_amount;
            $newRemainingDebt = $receivable->remaining_debt - $request->payment_amount;
            
            $receivable->paid_amount = $newPaidAmount;
            $receivable->remaining_debt = $newRemainingDebt;
            $receivable->status = $newRemainingDebt <= 0 ? 'paid' : 'partial';
            $receivable->save();
            
            $newPaidAmountTransaction = $transaction->paid_amount + $request->payment_amount;
            $transaction->paid_amount = $newPaidAmountTransaction;
            $transaction->payment_status = $newPaidAmountTransaction >= $transaction->total_amount ? 'paid' : 'partial';
            $transaction->save();
            

            
            $paymentMethod = $request->payment_method;
            $paymentMethodLabel = $this->getPaymentMethodLabel($paymentMethod);

            $paymentDate = $request->input('payment_date') ? Carbon::parse($request->input('payment_date')) : now();

            $buktiPath = null;
            if ($request->hasFile('bukti_pembayaran')) {
                $buktiPath = CloudinaryHelper::upload($request->file('bukti_pembayaran'), 'bukti-pembayaran');
            }

            ReceivablePayment::create([
                'transaction_id' => $transaction->id,
                'amount_paid' => $request->payment_amount,
                'payment_channel' => strtoupper($paymentMethod),
                'paid_at' => $paymentDate,
                'payment_date' => $paymentDate,
                'bukti_pembayaran' => $buktiPath,
                'cashier_id' => $request->user()->id,
            ]);

            // Sinkronisasi Profit untuk transaksi dari receivable
            // (pastikan key yang dipakai sama seperti Cashier::payReceivable)
            $transaction->loadMissing('details');
            \App\Models\Profit::where('transaction_id', $transaction->id)
                ->where('is_from_receivable', true)
                ->update(['receivable_status' => $receivable->status === 'paid' ? 'paid' : 'partial']);

            $this->logger->info('Receivable pay() profit sync', [
                'receivable_id' => $receivable->id,
                'transaction_id' => $transaction->id,
                'receivable_status_now' => $receivable->status,
            ]);

            DB::commit();

            $this->logger->info('Pembayaran piutang oleh Admin', [
                'receivable_id' => $receivable->id,
                'customer_name' => $receivable->customer_name,
                'payment_amount' => $request->payment_amount,
                'admin_id' => $request->user()->id
            ]);
            
            return $this->success([
                'receivable' => $receivable,
                'remaining_debt' => $newRemainingDebt,
                'remaining_debt_formatted' => 'Rp ' . number_format($newRemainingDebt, 0, ',', '.'),
                'status' => $receivable->status,
                'status_label' => $this->getStatusLabel($receivable->status),
            ], 'Pembayaran piutang berhasil', 200);
            
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationError($e->errors(), 'Data pembayaran tidak valid');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->error('Pay receivable error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memproses pembayaran', null, 500);
        }
    }

    /**
     * Final payment for installment (Pelunasan Cicilan Akhir)
     * POST /api/admin/receivables/{id}/pay-final
     */
    public function payFinal($id, Request $request)
    {
        DB::beginTransaction();
        
        try {
            $enabledMethods = [];
            if (Setting::getBool('payment_method_cash', true)) $enabledMethods[] = 'cash';
            if (Setting::getBool('payment_method_transfer', true)) $enabledMethods[] = 'transfer';
            if (Setting::getBool('payment_method_qris', true)) {
                $enabledMethods[] = 'qris_biasa';
                $enabledMethods[] = 'qris_statis';
            }
            if (Setting::getBool('payment_method_midtrans_qris', true)) $enabledMethods[] = 'midtrans_qris';

            $request->validate([
                'payment_method' => 'required|in:' . implode(',', $enabledMethods),
                'payment_date' => 'nullable|date',
                'bukti_pembayaran' => 'required_if:payment_method,transfer,qris_statis,qris_biasa|image|max:2048',
            ], [
                'payment_method.in' => 'Metode pembayaran yang dipilih sedang dinonaktifkan Admin atau tidak valid.',
                'bukti_pembayaran.required_if' => 'Bukti pembayaran wajib diunggah untuk metode Transfer dan QRIS.',
            ]);
            
            $receivable = Receivable::with('transaction')->findOrFail($id);
            $transaction = $receivable->transaction;
            
            if ($transaction->payment_method !== 'receivable') {
                if (isset($transaction)) DB::rollBack();
                return $this->error('Transaksi ini bukan transaksi piutang', null, 400);
            }
            
            if ($transaction->payment_status !== 'partial' && $transaction->payment_status !== 'unpaid') {
                DB::rollBack();
                return $this->error('Transaksi ini tidak dalam status cicilan aktif', null, 400);
            }
            
            if ($transaction->due_date && now()->gt(Carbon::parse($transaction->due_date))) {
                DB::rollBack();
                return $this->error('Maaf, tenggat waktu pembayaran telah lewat. Silakan hubungi admin.', null, 400);
            }
            
            $remainingBalance = $transaction->remaining_balance;
            
            if ($remainingBalance <= 0) {
                DB::rollBack();
                return $this->error('Tidak ada sisa pembayaran', null, 400);
            }
            
            if (in_array($request->payment_method, ['cash', 'transfer', 'qris_statis', 'qris_biasa'])) {
                $transaction->payment_status = 'paid';
                $transaction->paid_amount = $transaction->total_amount;
                $transaction->remaining_balance = 0;
                $transaction->installment_count = 2;
                $transaction->payment_method = $request->payment_method;
                $transaction->save();

                $receivable->paid_amount = $receivable->total_debt;
                $receivable->remaining_debt = 0;
                $receivable->status = 'paid';
                $receivable->save();

                $paymentDate = $request->input('payment_date') ? Carbon::parse($request->input('payment_date')) : now();

                $buktiPath = null;
                if ($request->hasFile('bukti_pembayaran')) {
                    $buktiPath = CloudinaryHelper::upload($request->file('bukti_pembayaran'), 'bukti-pembayaran');
                }

                ReceivablePayment::create([
                    'transaction_id' => $transaction->id,
                    'amount_paid' => $remainingBalance,
                    'payment_channel' => strtoupper($request->payment_method),
                    'paid_at' => $paymentDate,
                    'payment_date' => $paymentDate,
                    'bukti_pembayaran' => $buktiPath,
                    'cashier_id' => $request->user()->id,
                ]);

                DB::commit();

                $this->logger->info('Final payment via ' . strtoupper($request->payment_method) . ' for receivable', [
                    'transaction_id' => $transaction->id,
                    'receivable_id' => $receivable->id,
                    'amount' => $remainingBalance,
                    'admin_id' => $request->user()->id
                ]);

                return $this->success([
                    'transaction_id' => $transaction->id,
                    'invoice_number' => $transaction->invoice_number,
                    'customer_name' => $receivable->customer_name,
                    'amount_paid' => $remainingBalance,
                    'amount_paid_formatted' => 'Rp ' . number_format($remainingBalance, 0, ',', '.'),
                    'payment_method' => strtoupper($request->payment_method),
                    'status' => 'paid',
                    'status_label' => 'Lunas',
                    'remaining_debt' => 0,
                    'message' => 'Pelunasan piutang berhasil via ' . $request->payment_method,
                ], 'Pelunasan piutang berhasil', 200);

            } elseif (in_array($request->payment_method, ['qris_biasa', 'midtrans_qris'])) {
                // Menghindari penggunaan stdClass palsu. Kirim cloned instance atau modifikasi properties sementara
                $midtransTransaction = clone $transaction;
                $midtransTransaction->total_amount = $remainingBalance; 
                
                $customerName = $receivable->customer_name;
                
                $qrisResult = $this->midtransService->createQrisPayment($midtransTransaction, $customerName);
                
                if (!$qrisResult['success']) {
                    DB::rollBack();
                    return $this->error($qrisResult['message'], null, 500);
                }
                
                $transaction->midtrans_order_id = $qrisResult['order_id'];
                $transaction->midtrans_qr_url = $qrisResult['qr_url'];
                $transaction->save();
                
                DB::commit();
                
                $this->logger->info('QRIS generated for final payment', [
                    'transaction_id' => $transaction->id,
                    'receivable_id' => $receivable->id,
                    'amount' => $remainingBalance,
                    'order_id' => $qrisResult['order_id'],
                    'admin_id' => $request->user()->id
                ]);
                
                return $this->success([
                    'receivable_id' => $receivable->id,
                    'transaction_id' => $transaction->id,
                    'invoice_number' => $transaction->invoice_number,
                    'customer_name' => $receivable->customer_name,
                    'amount_to_pay' => $remainingBalance,
                    'amount_to_pay_formatted' => 'Rp ' . number_format($remainingBalance, 0, ',', '.'),
                    'qr_url' => $qrisResult['qr_url'],
                    'qr_string' => $qrisResult['qr_string'],
                    'order_id' => $qrisResult['order_id'],
                    'message' => 'Silakan scan QRIS untuk melunasi sisa pembayaran.',
                ], 'QRIS untuk pelunasan berhasil dibuat', 200);
            }
            
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationError($e->errors(), 'Data pembayaran tidak valid');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->error('Final payment error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memproses pembayaran: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Generate QRIS for receivable payment (Full Payment via QRIS)
     * POST /api/admin/receivables/{id}/generate-qris
     */
    public function generateQris($id, Request $request)
    {
        DB::beginTransaction();
        
        try {
            if (!Setting::getBool('payment_method_midtrans_qris', true)) {
                DB::rollBack();
                return $this->error('Metode pembayaran QRIS Midtrans sedang dinonaktifkan oleh Admin.', null, 400);
            }

            $receivable = Receivable::with('transaction')->findOrFail($id);
            $transaction = $receivable->transaction;
            
            if ($transaction->payment_method !== 'receivable') {
                DB::rollBack();
                return $this->error('Transaksi ini bukan transaksi piutang', null, 400);
            }
            
            if ($transaction->payment_status === 'paid') {
                DB::rollBack();
                return $this->error('Piutang ini sudah lunas', null, 400);
            }
            
            $remainingDebt = $receivable->remaining_debt;
            
            if ($remainingDebt <= 0) {
                DB::rollBack();
                return $this->error('Piutang sudah lunas', null, 400);
            }
            
            // Menggunakan properti objek kloningan asli agar aman dari type-hint model di service
            $midtransTransaction = clone $transaction;
            $midtransTransaction->total_amount = $remainingDebt;
            
            $customerName = $receivable->customer_name;
            
            $qrisResult = $this->midtransService->createQrisPayment($midtransTransaction, $customerName);
            
            if (!$qrisResult['success']) {
                DB::rollBack();
                return $this->error($qrisResult['message'], null, 500);
            }
            
            $transaction->midtrans_order_id = $qrisResult['order_id'];
            $transaction->midtrans_qr_url = $qrisResult['qr_url'];
            $transaction->save();
            
            DB::commit();
            
            $this->logger->info('QRIS generated for receivable payment', [
                'transaction_id' => $transaction->id,
                'receivable_id' => $receivable->id,
                'invoice_number' => $transaction->invoice_number,
                'amount' => $remainingDebt,
                'order_id' => $qrisResult['order_id'],
                'admin_id' => $request->user()->id
            ]);
            
            return $this->success([
                'receivable_id' => $receivable->id,
                'transaction_id' => $transaction->id,
                'invoice_number' => $transaction->invoice_number,
                'customer_name' => $receivable->customer_name,
                'amount_to_pay' => $remainingDebt,
                'amount_to_pay_formatted' => 'Rp ' . number_format($remainingDebt, 0, ',', '.'),
                'qr_url' => $qrisResult['qr_url'],
                'qr_string' => $qrisResult['qr_string'],
                'order_id' => $qrisResult['order_id'],
                'message' => 'Silakan scan QRIS untuk melunasi piutang',
            ], 'QRIS berhasil dibuat. Silakan scan QRIS untuk membayar.', 200);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->error('Generate QRIS error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat membuat QRIS: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Send WhatsApp reminder to Customer
     * POST /api/admin/receivables/{id}/reminder
     */
    public function sendReminder($id, Request $request)
    {
        try {
            $receivable = Receivable::findOrFail($id);
            
            if ($receivable->status === 'paid') {
                return $this->error('Piutang ini sudah lunas, tidak perlu reminder', null, 400);
            }
            
            $sent = $this->whatsappService->sendReceivableNotification($receivable, false);
            
            if ($sent) {
                $this->logger->info('Manual reminder sent for receivable by Admin', [
                    'receivable_id' => $receivable->id,
                    'customer_name' => $receivable->customer_name,
                    'remaining_debt' => $receivable->remaining_debt,
                    'admin_id' => $request->user()->id
                ]);
                
                return $this->success([
                    'receivable_id' => $receivable->id,
                    'customer_name' => $receivable->customer_name,
                    'remaining_debt' => $receivable->remaining_debt,
                    'remaining_debt_formatted' => 'Rp ' . number_format($receivable->remaining_debt, 0, ',', '.'),
                ], 'Pengingat tagihan berhasil dikirim via WhatsApp', 200);
            }
            
            return $this->error('Gagal mengirim pengingat. Periksa konfigurasi WhatsApp.', null, 500);
            
        } catch (\Exception $e) {
            $this->logger->error('Send manual reminder error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengirim pengingat', null, 500);
        }
    }

    /**
     * Show detail receivable
     * GET /api/admin/receivables/{id}
     */
    public function show($id)
    {
        try {
            $receivable = Receivable::with(['transaction.cashier', 'transaction.receivablePayments'])->findOrFail($id);
            
            $paymentsHistory = [];
            if ($receivable->transaction && $receivable->transaction->receivablePayments) {
                $paymentsHistory = $receivable->transaction->receivablePayments->map(function ($payment) {
                    $paymentMethodLabel = $this->getPaymentMethodLabel($payment->payment_channel);

                    $displayDate = $payment->payment_date ?? $payment->paid_at ?? $payment->created_at;
                    return [
                        'payment_method' => $payment->payment_channel,
                        'payment_method_label' => $paymentMethodLabel,
                        'paid_at' => $displayDate ? Carbon::parse($displayDate)->format('d/m/Y H:i:s') : '-',
                        'paid_at_formatted' => $displayDate ? Carbon::parse($displayDate)->format('d/m/Y H:i:s') : '-',
                        'payment_date' => $displayDate ? Carbon::parse($displayDate)->toDateTimeString() : null,
                        'payment_date_formatted' => $displayDate ? Carbon::parse($displayDate)->format('d/m/Y H:i:s') : '-',
                        'amount' => (float) $payment->amount_paid,
                        'amount_formatted' => 'Rp ' . number_format($payment->amount_paid, 0, ',', '.'),
                    ];
                });
            }
            
            $transaction = $receivable->transaction;
            $isInstallment = ($transaction->down_payment_amount ?? 0) > 0;
            
            $data = [
                'id' => $receivable->id,
                'transaction_id' => $receivable->transaction_id,
                'invoice_number' => $transaction->invoice_number ?? '-',
                'customer_name' => $receivable->customer_name,
                'customer_phone' => $receivable->customer_phone,
                'customer_address' => $receivable->customer_address,
                'total_debt' => (float) $receivable->total_debt,
                'total_debt_formatted' => 'Rp ' . number_format($receivable->total_debt, 0, ',', '.'),
                'paid_amount' => (float) $receivable->paid_amount,
                'paid_amount_formatted' => 'Rp ' . number_format($receivable->paid_amount, 0, ',', '.'),
                'remaining_debt' => (float) $receivable->remaining_debt,
                'remaining_debt_formatted' => 'Rp ' . number_format($receivable->remaining_debt, 0, ',', '.'),
                'due_date' => $receivable->due_date,
                'due_date_formatted' => $receivable->due_date ? Carbon::parse($receivable->due_date)->format('d/m/Y') : '-',
                'status' => $receivable->status,
                'status_label' => $this->getStatusLabel($receivable->status),
                'created_at' => $receivable->created_at,
'created_at_formatted' => $receivable->created_at ? Carbon::parse($receivable->created_at)->format('d/m/Y H:i:s') : '-',
                'cashier_name' => $transaction->cashier->name ?? '-',
                'payments_history' => $paymentsHistory,
                'qr_url' => $transaction->midtrans_qr_url ?? null,
                'can_pay_by_qris' => $receivable->status !== 'paid',
                'is_installment' => $isInstallment,
                'installment_count' => $transaction->installment_count ?? 0,
                'down_payment_amount' => $isInstallment ? (float) $transaction->down_payment_amount : null,
                'down_payment_formatted' => $isInstallment ? 'Rp ' . number_format($transaction->down_payment_amount, 0, ',', '.') : null,
                'remaining_balance' => $isInstallment ? (float) $transaction->remaining_balance : null,
                'remaining_balance_formatted' => $isInstallment ? 'Rp ' . number_format($transaction->remaining_balance, 0, ',', '.') : null,
                'can_pay_final' => $isInstallment && $transaction->payment_status === 'partial',
            ];
            
            return $this->success($data, 'Detail piutang berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Show receivable error: ' . $e->getMessage());
            return $this->error('Piutang tidak ditemukan', null, 404);
        }
    }

    /**
     * Get summary for receivable dashboard
     * GET /api/admin/receivables/summary
     */
    public function getSummary(Request $request)
    {
        try {
            $totalReceivableAmount = (float) Receivable::where('status', '!=', 'paid')->sum('remaining_debt');
            $totalPaidAmount = (float) Receivable::where('status', 'paid')->sum('total_debt');

            $summary = [
                'total_receivable' => $totalReceivableAmount,
                'total_receivable_formatted' => 'Rp ' . number_format($totalReceivableAmount, 0, ',', '.'),
                'total_paid' => $totalPaidAmount,
                'total_paid_formatted' => 'Rp ' . number_format($totalPaidAmount, 0, ',', '.'),
                'overdue_count' => Receivable::where('due_date', '<', now())
                    ->where('status', '!=', 'paid')
                    ->count(),
                'due_this_week_count' => Receivable::whereBetween('due_date', [now(), now()->addDays(7)])
                    ->where('status', '!=', 'paid')
                    ->count(),
                'total_customers' => Receivable::distinct('customer_name')->count('customer_name'),
                'collection_rate' => $this->calculateCollectionRate(),
            ];
            
            return $this->success($summary, 'Ringkasan piutang berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get receivable summary error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat ringkasan piutang', null, 500);
        }
    }

    /**
     * Get receivables that are due soon or overdue (for notification)
     * GET /api/admin/receivables/alert
     */
    public function getAlertReceivables(Request $request)
    {
        try {
            $dueSoon = Receivable::with('transaction')
                ->where('status', '!=', 'paid')
                ->whereBetween('due_date', [now(), now()->addDays(3)])
                ->get();
            
            $overdue = Receivable::with('transaction')
                ->where('status', '!=', 'paid')
                ->where('due_date', '<', now())
                ->get();
            
            // Perbaikan: Parse string tanggal ke instance Carbon agar aman dari fatal error method format() & diffInDays()
            $dueSoonData = $dueSoon->map(function ($item) {
                $dueDate = Carbon::parse($item->due_date);
                return [
                    'id' => $item->id,
                    'customer_name' => $item->customer_name,
                    'customer_phone' => $item->customer_phone,
                    'remaining_debt' => (float) $item->remaining_debt,
                    'remaining_debt_formatted' => 'Rp ' . number_format($item->remaining_debt, 0, ',', '.'),
                    'due_date' => $dueDate->format('d/m/Y'),
                    'days_left' => now()->diffInDays($dueDate, false),
                ];
            });
            
            $overdueData = $overdue->map(function ($item) {
                $dueDate = Carbon::parse($item->due_date);
                return [
                    'id' => $item->id,
                    'customer_name' => $item->customer_name,
                    'customer_phone' => $item->customer_phone,
                    'remaining_debt' => (float) $item->remaining_debt,
                    'remaining_debt_formatted' => 'Rp ' . number_format($item->remaining_debt, 0, ',', '.'),
                    'due_date' => $dueDate->format('d/m/Y'),
                    'days_overdue' => now()->diffInDays($dueDate, false),
                ];
            });
            
            return $this->success([
                'due_soon' => $dueSoonData,
                'overdue' => $overdueData,
                'due_soon_count' => $dueSoon->count(),
                'overdue_count' => $overdue->count(),
            ], 'Data alert piutang berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get alert receivables error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat data alert piutang', null, 500);
        }
    }

    /**
     * Calculate collection rate
     */
    private function calculateCollectionRate(): float
    {
        $totalReceivable = Receivable::sum('total_debt');
        $totalPaid = Receivable::sum('paid_amount');
        
        if ($totalReceivable <= 0) return 100.0;
        
        return round(($totalPaid / $totalReceivable) * 100, 2);
    }

    /**
     * Get status label in Indonesian
     */
    private function getStatusLabel($status): string
    {
        $labels = [
            'unpaid' => 'Belum Dibayar',
            'partial' => 'Cicilan',
            'paid' => 'Lunas',
        ];
        return $labels[$status] ?? $status;
    }

    private function getPaymentMethodLabel(?string $paymentMethod): string
    {
        $paymentMethod = strtoupper((string) $paymentMethod);

        $labels = [
            'CASH' => 'Tunai',
            'TRANSFER' => 'Transfer Bank',
            'QRIS_STATIS' => 'QRIS Statis',
            'MIDTRANS_QRIS' => 'QRIS Midtrans',
        ];

        return $labels[$paymentMethod] ?? $paymentMethod;
    }
}