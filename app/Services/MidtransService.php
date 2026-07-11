<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MidtransService
{
    protected $serverKey;
    protected $isProduction;

    public function __construct()
    {
        $this->serverKey = config('midtrans.server_key');
        $this->isProduction = config('midtrans.is_production', false);
    }

    /**
     * Membuat Pembayaran QRIS Baru ke Midtrans
     * Ditujukan untuk dipanggil oleh ReceivableController/Admin generate-qris.
     *
     * order_id menggunakan uniqid(true) yang include microsecond + entropy acak,
     * sehingga tidak akan collision meskipun request dikirim dalam milidetik berturutan.
     */
    public function createQrisPayment(object $transaction, string $customerName = 'Pelanggan Grosir'): array
    {
        try {
            // Sanitasi invoice_number (mengganti '/' dengan '-') untuk menghindari format yang dilarang Midtrans
            $sanitizedInvoice = str_replace('/', '-', $transaction->invoice_number);
            
            // Tambahkan random string 6 karakter untuk keunikan (misal saat retry pembayaran).
            // Contoh hasil: TX-INV-20260708-00082-A1B2C3
            // Panjang maksimal sekitar 29-32 karakter, sangat aman dari batas 50 karakter Midtrans.
            $orderId = 'TX-' . $sanitizedInvoice . '-' . \Illuminate\Support\Str::random(6);

            // Untuk QRIS piutang, gross_amount di-set melalui cloning caller ke $transaction->total_amount
            $amount = (float) ($transaction->total_amount ?? 0);

            $payload = [
                'payment_type' => 'qris',
                'transaction_details' => [
                    'order_id' => $orderId,
                    // Midtrans mensyaratkan gross_amount dalam range 0.01 - 99999999999.00
                    'gross_amount' => max(1, (int) round($amount)),
                ],
                'customer_details' => [
                    'first_name' => $customerName,
                ],
                'qris' => [
                    'acquirer' => 'gopay',
                ],
            ];

            $response = Http::withBasicAuth($this->serverKey, '')
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->getApiUrl() . '/charge', $payload);

            if ($response->successful()) {
                $data = $response->json();

                $qrUrl = null;
                if (isset($data['actions']) && is_array($data['actions'])) {
                    foreach ($data['actions'] as $action) {
                        if (($action['name'] ?? null) === 'generate-qr-code') {
                            $qrUrl = $action['url'] ?? null;
                            break;
                        }
                    }
                }

                return [
                    'success'        => true,
                    'order_id'       => $orderId,
                    'transaction_id' => $data['transaction_id'] ?? null,
                    'qr_url'         => $qrUrl ?? ($data['actions'][0]['url'] ?? null),
                    'qr_string'      => $data['qr_string'] ?? null,
                    'status'         => $data['transaction_status'] ?? 'pending',
                ];
            }

            // Ambil detail error dari Midtrans untuk logging & debugging Flutter
            $midtransBody        = $response->json();
            $midtransMessage     = $midtransBody['status_message'] ?? 'Tidak ada pesan dari Payment Gateway.';
            $midtransStatusCode  = $midtransBody['status_code']    ?? (string) $response->status();
            $midtransErrorCode   = $midtransBody['error_messages'] ?? null;

            Log::error('Midtrans QRIS creation failed', [
                'http_status'         => $response->status(),
                'midtrans_status_code'=> $midtransStatusCode,
                'midtrans_message'    => $midtransMessage,
                'midtrans_errors'     => $midtransErrorCode,
                'order_id_used'       => $orderId,
                'transaction_id'      => $transaction->id ?? null,
                'invoice_number'      => $transaction->invoice_number ?? null,
                'amount_used'         => $amount,
                'payload'             => $payload,
            ]);

            return [
                'success'              => false,
                // Pesan user-friendly untuk ditampilkan di UI Flutter
                'message'              => 'Gagal membuat QRIS: ' . $midtransMessage,
                // Field debug: dipakai Flutter untuk log/error detail, tidak ditampilkan ke end-user
                'midtrans_message'     => $midtransMessage,
                'midtrans_status_code' => $midtransStatusCode,
                'midtrans_errors'      => $midtransErrorCode,
            ];
        } catch (\Exception $e) {
            Log::error('Midtrans service error: ' . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return [
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat menghubungi Payment Gateway. Silakan coba beberapa saat lagi.',
            ];
        }
    }

    /**
     * Mengambil Status Transaksi Langsung dari Server Midtrans.
     */
    public function getTransactionStatus(string $orderId): array
    {
        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->withHeaders(['Accept' => 'application/json'])
                ->get($this->getApiUrl() . "/{$orderId}/status");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'status' => $response->json()['transaction_status'] ?? null,
                    'data' => $response->json(),
                ];
            }

            $errorBody = $response->body();
            $statusCode = $response->status();

            return [
                'success' => false,
                'message' => "Midtrans API error: HTTP {$statusCode} - {$errorBody}",
                'http_status' => $statusCode,
                'response_body' => $errorBody,
            ];
        } catch (\Exception $e) {
            Log::error('Midtrans status check error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Terjadi kendala saat menghubungkan status ke Midtrans.',
            ];
        }
    }

    protected function getApiUrl(): string
    {
        return $this->isProduction
            ? 'https://api.midtrans.com/v2'
            : 'https://api.sandbox.midtrans.com/v2';
    }

    /**
     * Validasi signature callback Midtrans.
     */
    public function verifySignature(array $payload, string $signature): bool
    {
        $expectedSignature = hash('sha512',
            ($payload['order_id'] ?? '') .
            ($payload['status_code'] ?? '') .
            ($payload['gross_amount'] ?? '') .
            $this->serverKey
        );

        return hash_equals($expectedSignature, $signature);
    }
}

