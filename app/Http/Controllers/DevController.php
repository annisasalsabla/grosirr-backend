<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Stock;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DevController extends Controller
{
    use ApiResponseTrait;

    /**
     * Simulate Midtrans payment success for testing
     * POST /api/dev/simulate-midtrans-payment/{transaction_id}
     *
     * Only active if APP_ENV != 'production'
     */
    public function simulateMidtransPayment(int $id)
    {
        // SECURITY: Only allow in non-production environments
        if (app()->environment('production')) {
            return $this->error('Endpoint not available in production', null, 404);
        }

        try {
            $transaction = Transaction::find($id);

            if (!$transaction) {
                return $this->error('Transaksi tidak ditemukan', null, 404);
            }

            // Validate: must be midtrans_qris payment method
            if ($transaction->payment_method !== 'midtrans_qris') {
                return $this->error('Hanya transaksi midtrans_qris yang dapat disimulasi', null, 400);
            }

            // Validate: must still be pending
            if ($transaction->payment_status !== 'pending') {
                return $this->error('Transaksi bukan status pending. Status saat ini: ' . $transaction->payment_status, null, 400);
            }

            DB::beginTransaction();

            try {
                // Update payment status to paid
                $transaction->update([
                    'payment_status' => 'paid',
                    'paid_amount' => $transaction->total_amount,
                    'paid_at' => now(),
                ]);

                // KURANGI STOK JIKA BELUM PERNAH DIKURANGI
                if (!$transaction->stock_deducted) {
                    $this->deductStock($transaction);

                    // Tandai stock sudah dikurangi
                    $transaction->update(['stock_deducted' => true]);
                }

                DB::commit();

                return $this->success([
                    'transaction' => $transaction->load('details.product'),
                    'invoice_number' => $transaction->invoice_number,
                    'payment_status' => 'paid',
                    'paid_at' => $transaction->paid_at,
                    'stock_deducted' => $transaction->stock_deducted,
                ], 'Simulasi payment Midtrans berhasil. Stok telah dikurangi.', 200);

            } catch (\Exception $e) {
                DB::rollBack();
                return $this->error('Error: ' . $e->getMessage(), null, 500);
            }

        } catch (\Exception $e) {
            return $this->error('Terjadi kesalahan: ' . $e->getMessage(), null, 500);
        }
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
                    'description' => 'Penjualan (Simulasi Midtrans Lunas) - Invoice: ' . $transaction->invoice_number,
                    'user_id' => $transaction->cashier_id,
                ]);
            }
        }
    }
}