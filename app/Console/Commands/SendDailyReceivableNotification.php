<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Receivable;
use App\Services\WhatsAppService;
use App\Services\SerenityLoggerService;
use Carbon\Carbon;

class SendDailyReceivableNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'receivable:send-daily-notification 
                            {--type=all : Jenis notifikasi (all, due-soon, overdue, new)}
                            {--days=7 : Jumlah hari sebelum jatuh tempo untuk notifikasi}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim notifikasi piutang harian ke Admin via WhatsApp';

    protected $whatsappService;
    protected $logger;

    public function __construct(WhatsAppService $whatsappService, SerenityLoggerService $logger)
    {
        parent::__construct();
        $this->whatsappService = $whatsappService;
        $this->logger = $logger;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=========================================');
        $this->info('Memulai pengiriman notifikasi piutang harian...');
        $this->info('Waktu: ' . Carbon::now()->format('Y-m-d H:i:s'));
        $this->info('=========================================');
        
        $type = $this->option('type');
        $daysBeforeDue = (int) $this->option('days');
        
        $totalSent = 0;
        $totalErrors = 0;
        
        // 1. Notifikasi piutang baru (7 hari setelah transaksi)
        if ($type == 'all' || $type == 'new') {
            $newReceivables = $this->getNewReceivables();
            $sent = $this->sendNotifications($newReceivables, 'new');
            $totalSent += $sent['sent'];
            $totalErrors += $sent['errors'];
        }
        
        // 2. Notifikasi piutang mendekati jatuh tempo
        if ($type == 'all' || $type == 'due-soon') {
            $dueSoonReceivables = $this->getDueSoonReceivables($daysBeforeDue);
            $sent = $this->sendNotifications($dueSoonReceivables, 'due-soon');
            $totalSent += $sent['sent'];
            $totalErrors += $sent['errors'];
        }
        
        // 3. Notifikasi piutang yang sudah jatuh tempo (overdue)
        if ($type == 'all' || $type == 'overdue') {
            $overdueReceivables = $this->getOverdueReceivables();
            $sent = $this->sendNotifications($overdueReceivables, 'overdue');
            $totalSent += $sent['sent'];
            $totalErrors += $sent['errors'];
        }
        
        $this->info('=========================================');
        $this->info("Notifikasi selesai dikirim!");
        $this->info("Total berhasil: {$totalSent}");
        $this->info("Total gagal: {$totalErrors}");
        $this->info('=========================================');
        
        $this->logger->info('Daily receivable notification completed', [
            'total_sent' => $totalSent,
            'total_errors' => $totalErrors,
            'type' => $type,
        ]);
        
        return Command::SUCCESS;
    }
    
    /**
     * Get piutang yang berusia 5 hari (hari ke-6 setelah transaksi)
     * Contoh: transaksi tgl 12 juni → notif masuk tgl 17 juni
     */
    private function getNewReceivables()
    {
        // Cek piutang yang berusia tepat 5 hari
        $fiveDaysAgo = Carbon::now()->subDays(5)->format('Y-m-d');

        return Receivable::where('status', '!=', 'paid')
            ->where('remaining_debt', '>', 0)
            ->whereDate('created_at', '=', $fiveDaysAgo)
            ->with('transaction.customer')
            ->get();
    }
    
    /**
     * Get receivables that are due TODAY (tepat di tanggal jatuh tempo)
     */
    private function getDueSoonReceivables(int $daysBeforeDue)
    {
        // Cek yang jatuh tempo hari INI (tepat tanggal jatuh tempo)
        return Receivable::where('status', '!=', 'paid')
            ->where('remaining_debt', '>', 0)
            ->whereDate('due_date', '=', Carbon::today())
            ->with('transaction')
            ->get();
    }
    
    /**
     * Get overdue receivables
     */
    private function getOverdueReceivables()
    {
        return Receivable::where('status', '!=', 'paid')
            ->where('remaining_debt', '>', 0)
            ->where('due_date', '<', Carbon::now())
            ->with('transaction')
            ->get();
    }
    
    /**
     * Send notifications for a collection of receivables
     */
    private function sendNotifications($receivables, string $type)
    {
        $sentCount = 0;
        $errorCount = 0;
        
        $typeLabel = match($type) {
            'new' => 'Piutang Hari ke-6',
            'due-soon' => 'Mendekati Jatuh Tempo',
            'overdue' => 'Sudah Jatuh Tempo',
            default => 'Notifikasi Piutang'
        };
        
        if ($receivables->isEmpty()) {
            $this->info("Tidak ada data {$typeLabel} untuk dikirim.");
            return ['sent' => 0, 'errors' => 0];
        }
        
        $this->info("Mengirim {$typeLabel} ({$receivables->count()} item)...");
        
        foreach ($receivables as $receivable) {
            try {
                $sent = $this->whatsappService->sendReceivableNotification($receivable, false);
                
                if ($sent) {
                    $sentCount++;
                    $this->line("  ✅ Notifikasi terkirim untuk: {$receivable->customer_name}");
                    
                    $this->logger->info('Receivable notification sent', [
                        'receivable_id' => $receivable->id,
                        'customer_name' => $receivable->customer_name,
                        'type' => $type,
                        'remaining_debt' => $receivable->remaining_debt,
                        'due_date' => $receivable->due_date,
                    ]);
                } else {
                    $errorCount++;
                    $this->error("  ❌ Gagal mengirim notifikasi untuk: {$receivable->customer_name}");
                }
                
                // Delay to avoid rate limiting
                usleep(500000); // 0.5 second delay
                
            } catch (\Exception $e) {
                $errorCount++;
                $this->error("  ❌ Error untuk {$receivable->customer_name}: " . $e->getMessage());
                
                $this->logger->error('Failed to send receivable notification', [
                    'receivable_id' => $receivable->id,
                    'customer_name' => $receivable->customer_name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        $this->info("  Selesai: {$sentCount} berhasil, {$errorCount} gagal");
        
        return ['sent' => $sentCount, 'errors' => $errorCount];
    }
}