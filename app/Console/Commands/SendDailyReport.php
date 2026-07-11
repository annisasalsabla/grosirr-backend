<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Transaction;
use App\Models\Profit;
use App\Services\WhatsAppService;
use App\Services\TelegramBotService;
use App\Services\SerenityLoggerService;


class SendDailyReport extends Command
{
    protected $signature = 'report:daily';
    protected $description = 'Kirim laporan harian ke Owner via WhatsApp dan Telegram';
    
    protected $whatsappService;
    protected $telegramService;
    protected $logger;
    
    public function __construct(
        WhatsAppService $whatsappService,
        TelegramBotService $telegramService,
        SerenityLoggerService $logger
    ) {
        parent::__construct();
        $this->whatsappService = $whatsappService;
        $this->telegramService = $telegramService;
        $this->logger = $logger;
    }
    
    public function handle()
    {
        $this->info('Mengirim laporan harian...');
        
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        
        $todaySales = Transaction::whereDate('created_at', $today)->sum('total_amount');
        $todayProfit = Profit::whereDate('profit_date', $today)->sum('profit_amount');
        $todayTransactions = Transaction::whereDate('created_at', $today)->count();
        
        $yesterdaySales = Transaction::whereDate('created_at', $yesterday)->sum('total_amount');
        
        $salesComparison = $todaySales - $yesterdaySales;
        $salesTrend = $salesComparison >= 0 ? '📈 Naik' : '📉 Turun';
        
        $message = "*LAPORAN HARIAN GROSIR TIGA BERSAUDARA*\n\n";
        $message .= "📅 Tanggal: " . now()->format('d/m/Y') . "\n\n";
        $message .= "*PENJUALAN:*\n";
        $message .= "💰 Total: Rp " . number_format($todaySales, 0, ',', '.') . "\n";
        $message .= "📊 {$salesTrend}: Rp " . number_format(abs($salesComparison), 0, ',', '.') . "\n";
        $message .= "🔄 Jumlah Transaksi: {$todayTransactions}\n\n";
        $message .= "*LABA:*\n";
        $message .= "💵 Total Laba: Rp " . number_format($todayProfit, 0, ',', '.') . "\n\n";
        $message .= "_Laporan ini dikirim otomatis oleh sistem._";
        
        // Kirim ke Telegram
        $this->telegramService->sendMessage($message);
        
        $this->logger->info('Daily report sent', [
            'date' => $today,
            'sales' => $todaySales,
            'profit' => $todayProfit
        ]);
        
        $this->info('Laporan harian berhasil dikirim!');
        
        return Command::SUCCESS;
    }
}