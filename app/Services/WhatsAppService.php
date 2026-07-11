<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected $apiUrl;
    protected $apiKey;
    protected $ownerNumber;
    protected $adminNumber;

    public function __construct()
    {
        $this->apiUrl = config('whatsapp.api_url', 'https://api.fonnte.com');
        $this->apiKey = config('whatsapp.api_key');
        $this->adminNumber = config('whatsapp.admin_number');
    }

    /**
     * Send WhatsApp message to any number
     */
    public function sendMessage(string $phone, string $message): bool
    {
        if (!$this->apiUrl || !$this->apiKey) {
            Log::error('WhatsApp config missing');
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
            ])->timeout(30)->post($this->apiUrl . '/send', [
                'target' => $phone,
                'message' => $message,
                'countryCode' => '62',
            ]);

            $success = $response->successful();

            NotificationLog::create([
                'type' => 'whatsapp',
                'recipient' => $phone,
                'subject' => 'Notifikasi Grosir Tiga Bersaudara',
                'message' => $message,
                'status' => $success ? 'sent' : 'failed',
                'error_message' => $success ? null : ($response->json()['reason'] ?? $response->body()),
            ]);

            if (!$success) {
                Log::error('WhatsApp send failed: ' . $response->body());
            }

            return $success;
        } catch (\Exception $e) {
            NotificationLog::create([
                'type' => 'whatsapp',
                'recipient' => $phone,
                'subject' => 'Notifikasi Grosir Tiga Bersaudara',
                'message' => $message,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            
            Log::error('WhatsApp send error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification to Admin
     */
    public function sendToAdmin(string $message): bool
    {
        if (!$this->adminNumber) {
            Log::error('Admin WhatsApp number not configured');
            return false;
        }
        return $this->sendMessage($this->adminNumber, $message);
    }

    /**
     * Send notification to Owner
     */
    public function sendToOwner(string $message): bool
    {
        if (!$this->ownerNumber) {
            Log::error('Owner WhatsApp number not configured');
            return false;
        }
        return $this->sendMessage($this->ownerNumber, $message);
    }

    /**
     * Send low stock notification to Admin
     */
    public function sendLowStockNotification($product): bool
    {
        $productType = $product->category == 'egg' ? 'Telur' : 'Beras';
        
        $message = "*⚠️ PERINGATAN STOK MENIPIS!*\n\n";
        $message .= "Produk: *{$product->name}*\n";
        $message .= "Kategori: {$productType}\n";
        $message .= "Stok Saat Ini: *{$product->stock} {$product->unit}*\n";
        $message .= "Stok Minimum: {$product->min_stock} {$product->unit}\n\n";
        $message .= "Segera lakukan penambahan stok sebelum kehabisan!\n";
        $message .= "📦 *Grosir Tiga Bersaudara*";

        return $this->sendToAdmin($message);
    }

    /**
     * Send receivable notification to Admin
     * Digunakan untuk:
     * 1. Notifikasi otomatis saat transaksi hutang baru dibuat
     * 2. Notifikasi manual saat Admin menekan tombol "Ingatkan" di Flutter
     *
     * @param $receivable Model receivable
     * @param bool $isNewTransaction true jika transaksi baru, false jika manual reminder
     */
    public function sendReceivableNotification($receivable, bool $isNewTransaction = false): bool
    {
        $formattedDate = date('d/m/Y', strtotime($receivable->due_date));
        
        // Tentukan Judul dan Header berdasarkan kondisi
        if ($isNewTransaction) {
            $message = "*🔴 NOTIFIKASI PIUTANG BARU!*\n\n";
            $message .= "Kepada Yth. Admin Grosir Tiga Bersaudara\n";
            $message .= "Telah tercatat transaksi piutang baru yang perlu dipantau:\n\n";
        } else {
            $message = "*🔔 PENGINGAT TAGIHAN PIUTANG!*\n\n";
            $message .= "Kepada Yth. Admin Grosir Tiga Bersaudara\n";
            $message .= "Berikut adalah pengingat tagihan piutang pelanggan untuk segera ditagih:\n\n";
        }

        // Isi Konten Data Piutang
        $message .= "👤 *Pelanggan:* {$receivable->customer_name}\n";
        $message .= "📞 Telepon: {$receivable->customer_phone}\n";
        $message .= "💰 Total Hutang: Rp " . number_format($receivable->total_debt, 0, ',', '.') . "\n";
        $message .= "💵 Sisa Hutang: *Rp " . number_format($receivable->remaining_debt, 0, ',', '.') . "*\n";
        $message .= "📅 Jatuh Tempo: {$formattedDate}\n\n";
        $message .= "⚠️ Mohon segera lakukan konfirmasi atau penagihan ke pelanggan!\n\n";
        $message .= "📦 *Grosir Tiga Bersaudara*";

        return $this->sendToAdmin($message);
    }

    /**
     * Alias untuk sendReceivableNotification - untuk kompatibilitas dengan command
     */
    public function notifyReceivableDue($receivable): bool
    {
        return $this->sendReceivableNotification($receivable, false);
    }

    /**
     * Kirim ringkasan piutang ke Admin
     */
    public function sendReceivableSummary($receivables, $summary): bool
    {
        $message = "*📊 RINGKASAN PIUTANG*\n\n";
        $message .= "📅 Periode: " . date('d/m/Y') . "\n\n";
        $message .= "💰 Total Piutang Aktif: Rp " . number_format($summary['total_receivable'], 0, ',', '.') . "\n";
        $message .= "🔴 Total Overdue: Rp " . number_format($summary['overdue_amount'], 0, ',', '.') . "\n";
        $message .= "👥 Jumlah Pelanggan: {$summary['total_customers']}\n";
        $message .= "⚠️ Overdue: {$summary['overdue_count']} transaksi\n\n";

        $message .= "📦 *Grosir Tiga Bersaudara*";

        return $this->sendToAdmin($message);
    }
}