<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Receivable;
use App\Services\WhatsAppService;
use App\Services\SerenityLoggerService;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ApiResponseTrait;

    protected $whatsappService;
    protected $logger;

    public function __construct(WhatsAppService $whatsappService, SerenityLoggerService $logger)
    {
        $this->whatsappService = $whatsappService;
        $this->logger = $logger;
    }

    /**
     * Test notifikasi stok menipis via API
     * GET /api/notifications/test/low-stock
     */
    public function testLowStock(Request $request)
    {
        try {
            $eggLimit = $request->get('egg_limit', 15);
            $riceLimit = $request->get('rice_limit', 15);

            // Ambil produk telur yang stok <= 15
            $lowEggProducts = Product::where('category', 'egg')
                ->where('stock', '<=', $eggLimit)
                ->where('stock', '>', 0)
                ->get();

            // Ambil produk telur yang stok = 0
            $outOfStockEggs = Product::where('category', 'egg')
                ->where('stock', 0)
                ->get();

            // Ambil produk beras yang stok <= 15
            $lowRiceProducts = Product::where('category', 'rice')
                ->where('stock', '<=', $riceLimit)
                ->where('stock', '>', 0)
                ->get();

            // Ambil produk beras yang stok = 0
            $outOfStockRice = Product::where('category', 'rice')
                ->where('stock', 0)
                ->get();

            // Build response data
            $responseData = [
                'parameters' => [
                    'egg_limit' => $eggLimit,
                    'rice_limit' => $riceLimit,
                ],
                'telur' => [
                    'kategori' => 'egg',
                    'batas_minimum' => $eggLimit . ' tray',
                    'stok_menipis' => $lowEggProducts->count(),
                    'stok_habis' => $outOfStockEggs->count(),
                    'items' => [],
                ],
                'beras' => [
                    'kategori' => 'rice',
                    'batas_minimum' => $riceLimit . ' karung',
                    'stok_menipis' => $lowRiceProducts->count(),
                    'stok_habis' => $outOfStockRice->count(),
                    'items' => [],
                ],
            ];

            // Add egg items to response
            foreach ($lowEggProducts as $product) {
                $responseData['telur']['items'][] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'stock' => $product->stock,
                    'unit' => $product->unit,
                    'status' => $product->stock == 0 ? 'habis' : 'menipis',
                ];
            }
            foreach ($outOfStockEggs as $product) {
                $responseData['telur']['items'][] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'stock' => $product->stock,
                    'unit' => $product->unit,
                    'status' => 'habis',
                ];
            }

            // Add rice items to response
            foreach ($lowRiceProducts as $product) {
                $responseData['beras']['items'][] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'stock' => $product->stock,
                    'unit' => $product->unit,
                    'status' => $product->stock == 0 ? 'habis' : 'menipis',
                ];
            }
            foreach ($outOfStockRice as $product) {
                $responseData['beras']['items'][] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'stock' => $product->stock,
                    'unit' => $product->unit,
                    'status' => 'habis',
                ];
            }

            // Check if should send WhatsApp notification
            $shouldNotify = $lowEggProducts->count() > 0 || $outOfStockEggs->count() > 0 ||
                            $lowRiceProducts->count() > 0 || $outOfStockRice->count() > 0;

            $whatsappSent = false;
            if ($shouldNotify) {
                $whatsappSent = $this->whatsappService->sendToAdmin(
                    "⚠️ *TEST NOTIFIKASI STOK MENIPIS*\n\n" .
                    "Tanggal: " . date('d/m/Y H:i') . "\n\n" .
                    "🥚 Telur (≤{$eggLimit} tray):\n" .
                    ($lowEggProducts->count() > 0 ? "   Ada {$lowEggProducts->count()} produk menipis" : "   Aman") . "\n\n" .
                    "🍚 Beras (≤{$riceLimit} karung):\n" .
                    ($lowRiceProducts->count() > 0 ? "   Ada {$lowRiceProducts->count()} produk menipis" : "   Aman") . "\n\n" .
                    "📦 *Grosir Tiga Bersaudara* (Test)"
                );
            }

            return $this->success([
                'low_stock_check' => $responseData,
                'should_send_notification' => $shouldNotify,
                'whatsapp_sent' => $whatsappSent,
            ], 'Cek stok menipis berhasil');

        } catch (\Exception $e) {
            $this->logger->error('Test low stock error: ' . $e->getMessage());
            return $this->error('Gagal cek stok: ' . $e->getMessage());
        }
    }

    /**
     * Test notifikasi piutang via API
     * GET /api/notifications/test/receivable
     * Logic: cek piutang yang berusia 5 hari (hari ke-6 setelah transaksi)
     * Contoh: transaksi tgl 12 juni → notif masuk tgl 17 juni
     */
    public function testReceivable(Request $request)
    {
        try {
            // Cek piutang yang berusia tepat 5 hari (hari ke-6)
            $fiveDaysAgo = Carbon::now()->subDays(5)->format('Y-m-d');

            $day6Receivables = Receivable::where('status', '!=', 'paid')
                ->where('remaining_debt', '>', 0)
                ->whereDate('created_at', '=', $fiveDaysAgo)
                ->with('transaction.customer')
                ->get();

            // Total piutang aktif
            $totalActive = Receivable::where('status', '!=', 'paid')->sum('remaining_debt');

            // Build response data
            $responseData = [
                'parameters' => [
                    'check_type' => 'hari_ke_6_setelah_transaksi',
                    'days_old' => 5,
                ],
                'piutang_hari_ke_6' => [
                    'tanggal_transaksi' => $fiveDaysAgo,
                    'count' => $day6Receivables->count(),
                    'total_amount' => (float) $day6Receivables->sum('remaining_debt'),
                    'items' => [],
                ],
                'total_aktif' => [
                    'count' => Receivable::where('status', '!=', 'paid')->count(),
                    'total_amount' => (float) $totalActive,
                ],
            ];

            // Add day 6 items
            foreach ($day6Receivables as $rec) {
                $transactionDate = $rec->created_at instanceof Carbon\CarbonImmutable
                    ? $rec->created_at->format('Y-m-d')
                    : ($rec->created_at ? $rec->created_at->format('Y-m-d') : '-');

                $responseData['piutang_hari_ke_6']['items'][] = [
                    'id' => $rec->id,
                    'customer_name' => $rec->customer_name,
                    'remaining_debt' => (float) $rec->remaining_debt,
                    'transaction_date' => $transactionDate,
                    'due_date' => $rec->due_date ? $rec->due_date->format('Y-m-d') : '-',
                ];
            }

            // Send WhatsApp if there are receivables on day 6
            $shouldNotify = $day6Receivables->count() > 0;
            $whatsappSent = false;

            if ($shouldNotify) {
                $message = "⚠️ *TEST NOTIFIKASI PIUTANG*\n\n";
                $message .= "Tanggal: " . date('d/m/Y H:i') . "\n\n";
                $message .= "📅 Hari ke-6: {$day6Receivables->count()} pelanggan\n";
                $message .= "💰 Total: Rp " . number_format($day6Receivables->sum('remaining_debt'), 0, ',', '.') . "\n\n";
                $message .= "📦 *Grosir Tiga Bersaudara* (Test)";

                $whatsappSent = $this->whatsappService->sendToAdmin($message);
            }

            return $this->success([
                'receivable_check' => $responseData,
                'should_send_notification' => $shouldNotify,
                'whatsapp_sent' => $whatsappSent,
            ], 'Cek piutang berhasil');

        } catch (\Exception $e) {
            $this->logger->error('Test receivable error: ' . $e->getMessage());
            return $this->error('Gagal cek piutang: ' . $e->getMessage());
        }
    }
}