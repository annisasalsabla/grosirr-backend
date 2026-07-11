<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Services\WhatsAppService;
use App\Services\SerenityLoggerService;
use Illuminate\Support\Facades\Cache;

class CheckLowStock extends Command
{
    protected $signature = 'stock:check-low
                            {--egg-limit=15 : Batas minimum stok telur (tray)}
                            {--rice-limit=15 : Batas minimum stok beras (karung)}
                            {--force : Force send notification even if already sent}';

    protected $description = 'Cek stok menipis dan kirim notifikasi ke Admin via WhatsApp';

    protected $whatsappService;
    protected $logger;

    public function __construct(WhatsAppService $whatsappService, SerenityLoggerService $logger)
    {
        parent::__construct();
        $this->whatsappService = $whatsappService;
        $this->logger = $logger;
    }

    public function handle()
    {
        $this->info('=========================================');
        $this->info('Memulai pengecekan stok menipis...');
        $this->info('=========================================');

        $eggLimit = (int) $this->option('egg-limit');
        $riceLimit = (int) $this->option('rice-limit');
        $force = $this->option('force');

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

        $totalNotified = 0;

        // Kirim notifikasi jika ada yang menipis atau habis
        if ($lowEggProducts->count() > 0 || $outOfStockEggs->count() > 0 ||
            $lowRiceProducts->count() > 0 || $outOfStockRice->count() > 0) {

            $cacheKey = "low_stock_notification_" . date('Y-m-d');

            if (!$force && Cache::get($cacheKey)) {
                $this->info('Notifikasi stok sudah terkirim hari ini, skip...');
            } else {
                $this->info('Mengirim notifikasi stok menipis ke Admin...');

                $message = $this->buildStockMessage(
                    $lowEggProducts, $outOfStockEggs,
                    $lowRiceProducts, $outOfStockRice,
                    $eggLimit, $riceLimit
                );

                $sent = $this->whatsappService->sendToAdmin($message);

                if ($sent) {
                    Cache::put($cacheKey, true, now()->endOfDay());
                    $totalNotified++;

                    $this->logger->info('Notifikasi stok menipis dikirim ke Admin', [
                        'low_eggs' => $lowEggProducts->count(),
                        'low_rice' => $lowRiceProducts->count(),
                        'out_eggs' => $outOfStockEggs->count(),
                        'out_rice' => $outOfStockRice->count(),
                    ]);
                }
            }
        } else {
            $this->info('Semua stok aman, tidak ada yang menipis!');
        }

        $this->info('=========================================');
        $this->info('Pengecekan stok selesai!');
        $this->info('=========================================');

        return Command::SUCCESS;
    }

    protected function buildStockMessage($lowEggProducts, $outOfStockEggs, $lowRiceProducts, $outOfStockRice, $eggLimit, $riceLimit)
    {
        $message = "*⚠️ PERINGATAN STOK MENIPIS!*\n\n";
        $message .= "📅 Tanggal: " . date('d/m/Y') . "\n\n";

        // Telur section
        $message .= "🥚 *TELUR (Batas: {$eggLimit} tray)*\n";
        if ($outOfStockEggs->count() > 0) {
            $message .= "🔴 *HAMPIR HABIS:*\n";
            foreach ($outOfStockEggs as $product) {
                $message .= "   • {$product->name}: {$product->stock} {$product->unit}\n";
            }
        }
        if ($lowEggProducts->count() > 0) {
            $message .= "⚠️ *MENIPIS:*\n";
            foreach ($lowEggProducts as $product) {
                $message .= "   • {$product->name}: {$product->stock} {$product->unit}\n";
            }
        }
        if ($outOfStockEggs->count() == 0 && $lowEggProducts->count() == 0) {
            $message .= "✅ Stok telur aman\n";
        }

        $message .= "\n";

        // Beras section
        $message .= "🍚 *BERAS (Batas: {$riceLimit} karung)*\n";
        if ($outOfStockRice->count() > 0) {
            $message .= "🔴 *HAMPIR HABIS:*\n";
            foreach ($outOfStockRice as $product) {
                $message .= "   • {$product->name}: {$product->stock} {$product->unit}\n";
            }
        }
        if ($lowRiceProducts->count() > 0) {
            $message .= "⚠️ *MENIPIS:*\n";
            foreach ($lowRiceProducts as $product) {
                $message .= "   • {$product->name}: {$product->stock} {$product->unit}\n";
            }
        }
        if ($outOfStockRice->count() == 0 && $lowRiceProducts->count() == 0) {
            $message .= "✅ Stok beras aman\n";
        }

        $message .= "\n";
        $message .= "Segera lakukan penambahan stok!\n";
        $message .= "📦 *Grosir Tiga Bersaudara*";

        return $message;
    }
}