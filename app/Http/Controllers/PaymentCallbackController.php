<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Stock;
use App\Services\MidtransService;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentCallbackController extends Controller
{
    protected $midtransService;
    protected $logger;

    public function __construct(MidtransService $midtransService, SerenityLoggerService $logger)
    {
        $this->midtransService = $midtransService;
        $this->logger = $logger;
    }

    /**
     * Handle Midtrans payment callback (webhook)
     * POST /api/payment/midtrans-callback
     */
    public function handleCallback(Request $request)
    {
        try {
            $payload = $request->all();
            
            // LOGGING AWAL (SEBELUM VERIFIKASI)
            Log::info('Midtrans webhook masuk', [
                'raw_payload' => $payload,
                'ip' => $request->ip(),
                'timestamp' => now(),
            ]);
            
            Log::info('Midtrans callback received', ['payload' => $payload]);
            
            $orderId = $payload['order_id'] ?? null;
            $transactionStatus = $payload['transaction_status'] ?? null;
            $paymentType = $payload['payment_type'] ?? null;
            $grossAmount = $payload['gross_amount'] ?? null;
            $midtransTransactionId = $payload['transaction_id'] ?? null;
            $signatureKey = $payload['signature_key'] ?? null;
            
            if (!$orderId || !$transactionStatus || !$signatureKey) {
                Log::error('Invalid Midtrans callback payload', ['payload' => $payload]);
                return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
            }
            
            // SECURITY CHECK: Validasi Signature Key dari MidtransService agar data tidak bisa dimanipulasi
            $isValidSignature = $this->midtransService->verifySignature($payload, $signatureKey);
            
            if (!$isValidSignature) {
                Log::warning('Unauthorized Midtrans callback attempt detected', ['order_id' => $orderId]);
                return response()->json(['status' => 'error', 'message' => 'Invalid signature key. Unauthorized.'], 403);
            }
            
            // Cari data transaksi berdasarkan ID Order unik Midtrans
            $transaction = Transaction::where('midtrans_order_id', $orderId)->first();
            
            if (!$transaction) {
                Log::error('Transaction not found for order_id', ['order_id' => $orderId]);
                return response()->json(['status' => 'error', 'message' => 'Transaction not found'], 404);
            }
            
            DB::beginTransaction();
            
            try {
                if ($transactionStatus === 'settlement' || $transactionStatus === 'capture') {
                    // Cek jenis pembayaran: DP (down payment) atau final payment
                    $isDownPayment = $transaction->payment_status === 'pending' && $transaction->installment_count === 0;
                    $isFinalPayment = $transaction->payment_status === 'partial' && $transaction->remaining_balance > 0;
                    
                    if ($isDownPayment) {
                        // Ini adalah pembayaran DP 50% untuk cicilan piutang
                        $this->processDownPayment($transaction, $midtransTransactionId);
                        $paymentLabel = 'down_payment';
                        
                    } elseif ($isFinalPayment) {
                        // Ini adalah pembayaran pelunasan final (cicilan ke-2)
                        $this->processFinalPayment($transaction, $midtransTransactionId);
                        $paymentLabel = 'final_payment';
                        
                    } else {
                        // Transaksi belanja biasa lunas langsung
                        $this->processRegularPayment($transaction, $midtransTransactionId);
                        $paymentLabel = 'regular';
                    }
                    
                    DB::commit();
                    
                    $this->logger->info('Midtrans payment settled successfully', [
                        'transaction_id' => $transaction->id,
                        'invoice_number' => $transaction->invoice_number,
                        'order_id' => $orderId,
                        'amount' => $grossAmount,
                        'payment_type' => $paymentLabel,
                    ]);
                    
                    return response()->json(['status' => 'success', 'message' => 'Payment processed successfully'], 200);
                    
                } elseif ($transactionStatus === 'pending') {
                    Log::info('Midtrans payment pending', ['order_id' => $orderId]);
                    DB::commit();
                    return response()->json(['status' => 'pending', 'message' => 'Payment is waiting for customer action'], 200);
                    
                } elseif (in_array($transactionStatus, ['expire', 'cancel', 'deny', 'failure'])) {
                    // Otomatis kembalikan stok fisik jika bayar gagal/kadaluwarsa
                    $this->processFailedPayment($transaction);
                    DB::commit();
                    
                    Log::info('Midtrans payment failed or expired', [
                        'order_id' => $orderId,
                        'status' => $transactionStatus,
                    ]);
                    
                    return response()->json(['status' => 'failed', 'message' => 'Payment expired or failed'], 200);
                }
                
                DB::commit();
                return response()->json(['status' => 'ok'], 200);
                
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Error processing Midtrans callback operations: ' . $e->getMessage());
                return response()->json(['status' => 'error', 'message' => 'Processing internal database error'], 500);
            }
            
        } catch (\Exception $e) {
            Log::error('Midtrans callback top-level error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Internal server error exception'], 500);
        }
    }

    /**
     * Process down payment (DP 50% untuk cicilan)
     */
    private function processDownPayment(Transaction $transaction, string $midtransTransactionId): void
    {
        // 1. IDEMPOTENCY GUARD: Cek apakah webhook ini sudah pernah diproses dan dicatat
        $existingPayment = \App\Models\ReceivablePayment::where('midtrans_transaction_id', $midtransTransactionId)->first();
        if ($existingPayment) {
            $this->logger->warning('Midtrans webhook retry detected and skipped (Down Payment)', [
                'transaction_id' => $transaction->id,
                'midtrans_transaction_id' => $midtransTransactionId
            ]);
            return; // Hentikan proses, payment sudah tercatat sebelumnya
        }

        // Ambil nilai DP sebelum field di-update
        $dpAmount = $transaction->down_payment_amount;

        // 2. HITUNG FEE DARI NOMINAL YANG BENAR-BENAR DIBAYAR (DP)
        $fee = \App\Services\PaymentFeeCalculator::calculate('midtrans_qris', $dpAmount);

        // Update status menjadi partial (sudah bayar DP)
        $transaction->payment_status = 'partial';
        $transaction->installment_count = 1;
        $transaction->paid_amount = $dpAmount;
        $transaction->due_date = now()->addDays(5);
        $transaction->save();
        
        // Cari receivable terkait hutang pelanggan grosir
        $receivable = Receivable::where('transaction_id', $transaction->id)->first();
        if ($receivable) {
            $receivable->paid_amount = $dpAmount;
            $receivable->remaining_debt = $transaction->remaining_balance;
            $receivable->status = 'partial';
            $receivable->due_date = now()->addDays(5);
            $receivable->save();
        }

        // Sinkronisasi Profit untuk transaksi dari receivable
        \App\Models\Profit::where('transaction_id', $transaction->id)
            ->where('is_from_receivable', true)
            ->update(['receivable_status' => 'partial']);
        
        // Catat pembayaran DP ke riwayat cicilan piutang (BESERTA FEE)
        ReceivablePayment::create([
            'transaction_id' => $transaction->id,
            'amount_paid' => $dpAmount,
            'payment_channel' => 'MIDTRANS_QRIS',
            'paid_at' => now(),
            'payment_date' => now(),
            'midtrans_transaction_id' => $midtransTransactionId,
            'payment_fee_percentage' => $fee['percentage'],
            'payment_fee_amount' => $fee['amount'],
        ]);
        
        $this->logger->info('Down payment processed for installment', [
            'transaction_id' => $transaction->id,
            'down_payment_amount' => $dpAmount,
            'remaining_balance' => $transaction->remaining_balance,
            'due_date' => $transaction->due_date,
        ]);
    }

    /**
     * Process final payment (cicilan ke-2)
     */
    private function processFinalPayment(Transaction $transaction, string $midtransTransactionId): void
    {
        // 1. IDEMPOTENCY GUARD: Cek apakah webhook ini sudah pernah diproses dan dicatat
        $existingPayment = \App\Models\ReceivablePayment::where('midtrans_transaction_id', $midtransTransactionId)->first();
        if ($existingPayment) {
            $this->logger->warning('Midtrans webhook retry detected and skipped (Final Payment)', [
                'transaction_id' => $transaction->id,
                'midtrans_transaction_id' => $midtransTransactionId
            ]);
            return; // Hentikan proses, payment sudah tercatat sebelumnya
        }

        // Ambil sisa piutang yang harus dibayar SEBELUM nilainya di-set ke 0
        $finalAmountPaid = $transaction->remaining_balance;

        // 2. HITUNG FEE DARI NOMINAL YANG BENAR-BENAR DIBAYAR (SISA CICILAN)
        $fee = \App\Services\PaymentFeeCalculator::calculate('midtrans_qris', $finalAmountPaid);

        // Update status transaksi menjadi paid (lunas)
        $transaction->payment_status = 'paid';
        $transaction->installment_count = 2;
        $transaction->paid_amount = $transaction->total_amount;
        $transaction->payment_method = 'qris'; // Memastikan tercatat sebagai digital
        $transaction->remaining_balance = 0;
        $transaction->save();
        
        // Update receivable menjadi lunas
        $receivable = Receivable::where('transaction_id', $transaction->id)->first();
        if ($receivable) {
            $receivable->paid_amount = $receivable->total_debt;
            $receivable->remaining_debt = 0;
            $receivable->status = 'paid';
            $receivable->save();
        }

        // Sinkronisasi Profit untuk transaksi dari receivable
        \App\Models\Profit::where('transaction_id', $transaction->id)
            ->where('is_from_receivable', true)
            ->update(['receivable_status' => 'paid']);
        
        // Catat pembayaran final dengan nominal sisa cicilan (BESERTA FEE)
        ReceivablePayment::create([
            'transaction_id' => $transaction->id,
            'amount_paid' => $finalAmountPaid,
            'payment_channel' => 'MIDTRANS_QRIS',
            'paid_at' => now(),
            'payment_date' => now(),
            'midtrans_transaction_id' => $midtransTransactionId,
            'payment_fee_percentage' => $fee['percentage'],
            'payment_fee_amount' => $fee['amount'],
        ]);
        
        $this->logger->info('Final payment processed for installment', [
            'transaction_id' => $transaction->id,
            'final_payment_amount' => $finalAmountPaid,
        ]);
    }

    /**
     * Process regular payment (non-installment)
     */
    private function processRegularPayment(Transaction $transaction, string $midtransTransactionId): void
    {
        $transaction->payment_status = 'paid';
        $transaction->paid_amount = $transaction->total_amount;
        $transaction->save();

        // KURANGI STOK JIKA BELUM PERNAH DIKURANGI (cek flag stock_deducted)
        // Ini mencegah double-kurang jika webhook dan polling berjalan bersamaan
        if (!$transaction->stock_deducted) {
            $this->deductStock($transaction);

            // Tandai stock sudah dikurangi
            $transaction->update(['stock_deducted' => true]);
        }

        $this->logger->info('Regular payment processed', [
            'transaction_id' => $transaction->id,
            'amount' => $transaction->total_amount,
        ]);
    }

    /**
     * Helper: Kurangi stok untuk transaksi (saat payment berhasil)
     */
    private function deductStock(Transaction $transaction): void
    {
        $transaction->load('details.product');

        foreach ($transaction->details as $detail) {
            $product = $detail->product;
            if ($product) {
                $product->decreaseStock($detail->quantity);

                Stock::create([
                    'product_id' => $product->id,
                    'type' => 'out',
                    'quantity' => $detail->quantity,
                    'description' => 'Penjualan (Midtrans Lunas) - Invoice: ' . $transaction->invoice_number,
                    'user_id' => $transaction->cashier_id,
                ]);
            }
        }
    }

    /**
     * Process failed payment (TIDAK rollback stok karena tidak pernah dikurangi)
     */
    private function processFailedPayment(Transaction $transaction): void
    {
        // Cek apakah transaksi memang belum dibayar sama sekali
        if ($transaction->payment_status !== 'paid' && $transaction->payment_status !== 'partial') {
            // Update status pembayaran menjadi failed
            $transaction->payment_status = 'failed';
            $transaction->save();

            // TIDAK PERLU KEMBALIKAN STOK karena stok tidak pernah dikurangi untuk midtrans_qris
            // Stok hanya dikurangi SAAT payment_status = 'paid' (via webhook/cek status)

            $this->logger->info('Payment failed - no stock reversal needed', [
                'transaction_id' => $transaction->id,
                'invoice_number' => $transaction->invoice_number,
                'note' => 'Stock was not deducted for pending midtrans_qris transaction',
            ]);
        }
    }
}