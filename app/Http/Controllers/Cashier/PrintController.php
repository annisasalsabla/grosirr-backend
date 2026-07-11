<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;

class PrintController extends Controller
{
    use ApiResponseTrait;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    public function printStruk($transactionId, Request $request)
    {
        try {
            $transaction = Transaction::with(['details.product', 'cashier', 'receivable'])
                ->where('cashier_id', $request->user()->id)
                ->findOrFail($transactionId);
            
            $strukHtml = $this->generateStrukHtml($transaction);
            
            $this->logger->info('Struk printed by Cashier', [
                'transaction_id' => $transactionId,
                'cashier_id' => $request->user()->id
            ]);
            
            return $this->success([
                'html' => $strukHtml,
                'invoice_number' => $transaction->invoice_number,
            ], 'Struk berhasil dibuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Print struk error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mencetak struk', null, 500);
        }
    }

    private function generateStrukHtml($transaction)
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: monospace; width: 300px; margin: 0 auto; padding: 10px; }
                .header { text-align: center; margin-bottom: 10px; }
                .title { font-size: 16px; font-weight: bold; }
                .subtitle { font-size: 12px; }
                .divider { border-top: 1px dashed #000; margin: 10px 0; }
                .row { display: flex; justify-content: space-between; margin: 5px 0; }
                .item-name { font-size: 12px; }
                .item-detail { font-size: 11px; margin-left: 10px; }
                .total { font-weight: bold; margin-top: 10px; }
                .footer { text-align: center; margin-top: 15px; font-size: 11px; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="title">GROSIR TIGA BERSAUDARA</div>
                <div class="subtitle">Jl. Rimbo Data, Bandar Buat, Padang</div>
                <div class="subtitle">Telp: 082181769006</div>
            </div>
            <div class="divider"></div>
            <div class="row">
                <span>Invoice:</span>
                <span>{$transaction->invoice_number}</span>
            </div>
            <div class="row">
                <span>Tanggal:</span>
                <span>{$transaction->created_at->format('d/m/Y H:i:s')}</span>
            </div>
            <div class="row">
                <span>Kasir:</span>
                <span>{$transaction->cashier->name}</span>
            </div>
            <div class="divider"></div>
HTML;
        
        foreach ($transaction->details as $detail) {
            $html .= <<<HTML
            <div class="item-name">{$detail->product->name}</div>
            <div class="item-detail">
                <div class="row">
                    <span>{$detail->quantity} x {$detail->product->unit}</span>
                    <span>Rp " . number_format($detail->subtotal, 0, ',', '.') . "</span>
                </div>
            </div>
HTML;
        }
        
        $html .= <<<HTML
            <div class="divider"></div>
            <div class="row total">
                <span>TOTAL:</span>
                <span>Rp " . number_format($transaction->total_amount, 0, ',', '.') . "</span>
            </div>
            <div class="row">
                <span>Dibayar:</span>
                <span>Rp " . number_format($transaction->paid_amount, 0, ',', '.') . "</span>
            </div>
            <div class="row">
                <span>Kembalian:</span>
                <span>Rp " . number_format($transaction->change_due, 0, ',', '.') . "</span>
            </div>
            <div class="row">
                <span>Metode:</span>
                <span>{$this->getPaymentMethodText($transaction->payment_method, $transaction->dp_payment_method)}</span>
            </div>
HTML;
        
        if ($transaction->payment_method === 'receivable' && $transaction->receivable) {
            $html .= <<<HTML
            <div class="divider"></div>
            <div class="row">
                <span>Pelanggan:</span>
                <span>{$transaction->receivable->customer_name}</span>
            </div>
            <div class="row">
                <span>Jatuh Tempo:</span>
                <span>{$transaction->receivable->due_date->format('d/m/Y')}</span>
            </div>
            <div class="row">
                <span>Sisa Hutang:</span>
                <span>Rp " . number_format($transaction->receivable->remaining_debt, 0, ',', '.') . "</span>
            </div>
HTML;
        }
        
        $html .= <<<HTML
            <div class="divider"></div>
            <div class="footer">
                Terima kasih atas kunjungan Anda<br>
                Barang yang sudah dibeli tidak dapat dikembalikan
            </div>
        </body>
        </html>
HTML;
        
        return $html;
    }

    private function getPaymentMethodText($method, $dpMethod = null)
    {
        if ($method === 'receivable') {
            return $dpMethod ? 'Kredit (DP via ' . ucfirst($dpMethod) . ')' : 'Kredit';
        }
        return match($method) {
            'cash' => 'Tunai',
            'transfer' => 'Transfer Bank',
            'qris', 'qris_statis', 'qris_biasa' => 'QRIS',
            'midtrans_qris' => 'QRIS Otomatis',
            default => $method,
        };
    }
}