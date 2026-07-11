<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\Receivable;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use App\Helpers\CloudinaryHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Setting;
use Illuminate\Validation\ValidationException;


class ReceivableController extends Controller
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
            
            $receivables = Receivable::with('transaction.cashier')
                ->where('status', '!=', 'paid')
                ->orderBy('due_date', 'asc')
                ->paginate($perPage);
            
            $summary = [
                'total_debt' => Receivable::where('status', '!=', 'paid')->sum('remaining_debt'),
                'overdue_count' => Receivable::where('due_date', '<', now())
                    ->where('status', '!=', 'paid')
                    ->count(),
                'due_this_week' => Receivable::whereBetween('due_date', [now(), now()->addDays(7)])
                    ->where('status', '!=', 'paid')
                    ->count(),
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

    public function payReceivable($id, Request $request)
    {
        DB::beginTransaction();
        
        try {
            // Support field aliasing: client can send either payment_channel OR payment_method
            // STANDARISASI: Hanya menerima lowercase dari API agar seragam dengan endpoint lain
            $paymentChannel = $request->input('payment_channel') ?? $request->input('payment_method');
            $paymentChannel = is_string($paymentChannel) ? strtolower($paymentChannel) : $paymentChannel;

            $enabledChannels = [];
            if (Setting::getBool('payment_method_cash', true)) {
                $enabledChannels[] = 'cash';
            }
            if (Setting::getBool('payment_method_transfer', true)) {
                $enabledChannels[] = 'transfer';
            }
            if (Setting::getBool('payment_method_qris', true)) {
                $enabledChannels[] = 'qris_statis';
            }
            if (Setting::getBool('payment_method_midtrans_qris', true)) {
                $enabledChannels[] = 'midtrans_qris';
            }

            $request->merge(['payment_channel' => $paymentChannel]);

            $request->validate([
                'payment_channel' => 'nullable|in:' . implode(',', $enabledChannels),
                'payment_method' => 'nullable|in:' . implode(',', $enabledChannels),
                'payment_amount' => 'required_if:payment_channel,cash,transfer|numeric|min:1',
                'payment_date' => 'nullable|date',
                'bukti_pembayaran' => 'required_if:payment_channel,qris_statis,transfer|image|max:2048',
            ], [
                'payment_channel.in' => 'Metode pembayaran yang dipilih sedang dinonaktifkan Admin atau tidak valid.',
                'payment_method.in' => 'Metode pembayaran yang dipilih sedang dinonaktifkan Admin atau tidak valid.',
                'bukti_pembayaran.required_if' => 'Bukti pembayaran wajib diunggah untuk metode QRIS Statis dan Transfer.',
            ]);

            // BACKWARD-COMPATIBILITY: Ubah menjadi UPPERCASE sebelum simpan ke database
            // karena secara historis tabel receivable_payments menggunakan UPPERCASE.
            if ($paymentChannel) {
                $paymentChannel = strtoupper($paymentChannel);
            }

            $paymentDate = $request->input('payment_date') ? \Carbon\Carbon::parse($request->input('payment_date')) : now();

            $receivable = Receivable::with('transaction')->findOrFail($id);
            $transaction = $receivable->transaction;

            if (!$paymentChannel) {
                return $this->error('payment_channel/payment_method wajib diisi', null, 422);
            }
            
            
            
            if ($receivable->status === 'paid') {
                return $this->error('Piutang/Cicilan ini sudah lunas sepenuhnya', null, 400);
            }

            // Hitung sisa tagihan akhir secara presisi
            $sisaHutang = $receivable->remaining_debt;

            // MIDTRANS_QRIS - nominal otomatis terkunci
            if ($paymentChannel === 'MIDTRANS_QRIS') {
                $midtransService = app(\App\Services\MidtransService::class);

                $tempTransaction = new \stdClass();
                $tempTransaction->total_amount = $sisaHutang;
                $tempTransaction->invoice_number = $transaction->invoice_number . '-REV2';
                $tempTransaction->id = $transaction->id;

                $midtransResult = $midtransService->createQrisPayment($tempTransaction, $receivable->customer_name);

                if (!$midtransResult['success']) {
                    DB::rollBack();
                    return $this->error('Gagal membuat sistem pembayaran Midtrans: ' . $midtransResult['message'], null, 500);
                }

                $transaction->midtrans_order_id = $midtransResult['order_id'];
                $transaction->midtrans_qr_url = $midtransResult['qr_url'];
                $transaction->save();

                DB::commit();
                return $this->success([
                    'qr_url' => $midtransResult['qr_url'],
                    'qr_string' => $midtransResult['qr_string'],
                    'order_id' => $midtransResult['order_id'],
                    'amount_to_pay' => $sisaHutang,
                    'message' => 'Silakan scan QRIS untuk pelunasan cicilan ke-2.'
                ], 'QRIS Pelunasan berhasil dibuat');
            }

            // QRIS_STATIS - Kasir upload bukti pembayaran, langsung lunaskan
            if ($paymentChannel === 'QRIS_STATIS') {
                $amountPaid = $sisaHutang; // Langsung lunaskan sisa hutang

                // Simpan bukti pembayaran
                $buktiPembayaranPath = null;
                if ($request->hasFile('bukti_pembayaran')) {
                    $buktiPembayaranPath = CloudinaryHelper::upload($request->file('bukti_pembayaran'), 'bukti-pembayaran');
                }

                $receivable->paid_amount += $amountPaid;
                $receivable->remaining_debt = 0;
                $receivable->status = 'paid';
                $receivable->save();

                // Sinkronisasi Profit
                $transaction->loadMissing('details');
                \App\Models\Profit::where('transaction_id', $transaction->id)
                    ->where('is_from_receivable', true)
                    ->update(['receivable_status' => 'paid']);

                $transaction->paid_amount += $amountPaid;
                $transaction->remaining_balance = 0;
                $transaction->payment_status = 'paid';
                $transaction->save();

                DB::table('receivable_payments')->insert([
                    'transaction_id' => $transaction->id,
                    'amount_paid' => $amountPaid,
                    'payment_channel' => 'QRIS_STATIS',
                    'paid_at' => $paymentDate,
                    'payment_date' => $paymentDate,
                    'bukti_pembayaran' => $buktiPembayaranPath,
                    'cashier_id' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::commit();

                return $this->success([
                    'message' => 'Pembayaran piutang via QRIS Statis berhasil.',
                    'amount_paid' => $amountPaid,
                    'remaining_debt' => 0,
                    'status' => 'paid',
                    'bukti_pembayaran_url' => $buktiPembayaranPath ? asset('storage/' . $buktiPembayaranPath) : null,
                ], 'Pembayaran piutang berhasil');
            }

            // QRIS_BIASA - Pembeli input nominal sendiri (langsung lunas)
            if ($paymentChannel === 'QRIS_BIASA') {
                $amountPaid = $sisaHutang; // Langsung lunaskan

                $receivable->paid_amount += $amountPaid;
                $receivable->remaining_debt = 0;
                $receivable->status = 'paid';
                $receivable->save();

                // Sinkronisasi Profit untuk transaksi dari receivable
                $transaction->loadMissing('details');
                \App\Models\Profit::where('transaction_id', $transaction->id)
                    ->where('is_from_receivable', true)
                    ->update(['receivable_status' => 'paid']);


                $transaction->paid_amount += $amountPaid;
                $transaction->remaining_balance = 0;
                $transaction->installment_count = 2;
                $transaction->payment_status = 'paid';
                $transaction->payment_method = 'qris_biasa';
                $transaction->save();

                DB::table('receivable_payments')->insert([
                    'transaction_id' => $transaction->id,
                    'amount_paid' => $amountPaid,
                    'payment_channel' => 'QRIS_BIASA',
                    'paid_at' => $paymentDate,
                    'payment_date' => $paymentDate,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::commit();

                return $this->success([
                    'message' => 'Pembayaran piutang via QRIS Biasa berhasil.',
                    'amount_paid' => $amountPaid,
                    'remaining_debt' => 0,
                    'status' => 'paid'
                ], 'Pembayaran piutang berhasil');
            }

            // CASH atau TRANSFER - Pembayaran manual
            if (in_array($paymentChannel, ['CASH', 'TRANSFER'])) {
                if ($request->payment_amount > $sisaHutang) {
                    return $this->error('Jumlah pembayaran uang tunai melebihi sisa hutang asli', null, 400);
                }

                $amountPaid = $request->payment_amount;

                // 1. Update Tabel Receivables
                $receivable->paid_amount += $amountPaid;
                $receivable->remaining_debt -= $amountPaid;
                $receivable->status = $receivable->remaining_debt <= 0 ? 'paid' : 'partial';
                $receivable->save();

                // 2b. Sinkronisasi Profit untuk transaksi dari receivable
                // Profit dibuat saat transaksi receivable dibuat (unpaid) dan harus ikut berubah saat cicilan terjadi.
                $transaction->loadMissing('details');

                \App\Models\Profit::where('transaction_id', $transaction->id)
                    ->where('is_from_receivable', true)
                    ->update(['receivable_status' => $receivable->status === 'paid' ? 'paid' : 'partial']);

                \Illuminate\Support\Facades\Log::info('Receivable payReceivable profit sync', [
                    'receivable_id' => $receivable->id,
                    'transaction_id' => $transaction->id,
                    'receivable_status_now' => $receivable->status,
                    'profit_rows_updated' => \App\Models\Profit::where('transaction_id', $transaction->id)
                        ->where('is_from_receivable', true)
                        ->whereNotNull('receivable_status')
                        ->count(),
                ]);
                
                
                // 2. Update Tabel Transaksi Utama (Ubah ke PAID jika sudah tidak ada sisa)
                $transaction->paid_amount += $amountPaid;

                $transaction->remaining_balance = $receivable->remaining_debt;
                $transaction->installment_count = 2; // Menandakan cicilan ke-2 selesai
                $transaction->payment_status = $transaction->remaining_balance <= 0 ? 'paid' : 'partial';
                $transaction->save();
                
                // Simpan bukti pembayaran jika transfer
                $buktiPembayaranPath = null;
                if ($request->hasFile('bukti_pembayaran')) {
                    $buktiPembayaranPath = CloudinaryHelper::upload($request->file('bukti_pembayaran'), 'bukti-pembayaran');
                }

                // 3. Catat riwayat pembayaran ke receivable_payments
                DB::table('receivable_payments')->insert([
                    'transaction_id' => $transaction->id,
                    'amount_paid' => $amountPaid,
                    'payment_channel' => $paymentChannel,
                    'paid_at' => $paymentDate,
                    'payment_date' => $paymentDate,
                    'bukti_pembayaran' => $buktiPembayaranPath,
                    'cashier_id' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::commit();

                $this->logger->info('Pelunasan piutang via ' . $request->payment_channel . ' Sukses', [
                    'receivable_id' => $receivable->id,
                    'customer_name' => $receivable->customer_name,
                    'amount_paid' => $amountPaid
                ]);
                
                return $this->success([
                    'receivable' => $receivable,
                    'remaining_debt' => $receivable->remaining_debt,
                    'status' => $receivable->status,
                    'payment_channel' => $request->payment_channel
                ], 'Pembayaran piutang via ' . $request->payment_channel . ' berhasil.', 200);
            }
            
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationError($e->errors(), 'Data pembayaran tidak valid');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Gagal memproses pembayaran pelunasan: ' . $e->getMessage(), null, 500);
        }
    }
}