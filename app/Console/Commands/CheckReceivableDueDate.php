<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Receivable;
use App\Services\WhatsAppService;
use App\Services\SerenityLoggerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class CheckReceivableDueDate extends Command
{
    protected $signature = 'receivable:check-due-date
                            {--days=5 : Jumlah hari sebelum jatuh tempo untuk notifikasi}
                            {--send-summary : Kirim ringkasan mingguan}
                            {--force : Force send notification even if already sent}';
    
    protected $description = 'Cek piutang jatuh tempo dan kirim notifikasi ke Admin via WhatsApp';

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
        $this->info('Memulai pengecekan piutang jatuh tempo...');
        $this->info('=========================================');
        
        $daysBeforeDue = $this->option('days');
        $sendSummary = $this->option('send-summary');
        $force = $this->option('force');
        
        $today = Carbon::today();
        $targetDate = $today->copy()->addDays($daysBeforeDue);
        
        // Piutang yang akan jatuh tempo dalam X hari (belum overdue)
        $dueSoon = Receivable::where('status', '!=', 'paid')
            ->whereBetween('due_date', [$today, $targetDate])
            ->where('due_date', '>', $today)
            ->get();
        
        // Piutang yang sudah lewat jatuh tempo (overdue)
        $overdue = Receivable::where('status', '!=', 'paid')
            ->where('due_date', '<', $today)
            ->get();
        
        $totalNotified = 0;
        
        // Proses piutang yang akan jatuh tempo
        foreach ($dueSoon as $receivable) {
            $notifiedKey = "receivable_notified_{$receivable->id}_due_soon";
            
            if (!$force && Cache::get($notifiedKey)) {
                continue;
            }
            
            $this->info("Mengirim notifikasi untuk pelanggan: {$receivable->customer_name}");
            
            $sent = $this->whatsappService->notifyReceivableDue($receivable);
            
            if ($sent) {
                Cache::put($notifiedKey, true, now()->addDays(1));
                $totalNotified++;
                
                $this->logger->info("Notifikasi piutang jatuh tempo dikirim ke Admin", [
                    'receivable_id' => $receivable->id,
                    'customer_name' => $receivable->customer_name,
                    'days_left' => $today->diffInDays($receivable->due_date)
                ]);
            }
        }
        
        // Proses piutang yang sudah overdue
        foreach ($overdue as $receivable) {
            $notifiedKey = "receivable_notified_{$receivable->id}_overdue";
            
            if (!$force && Cache::get($notifiedKey)) {
                continue;
            }
            
            $this->info("Mengirim notifikasi overdue untuk pelanggan: {$receivable->customer_name}");
            
            $sent = $this->whatsappService->notifyReceivableDue($receivable);
            
            if ($sent) {
                Cache::put($notifiedKey, true, now()->addDays(1));
                $totalNotified++;
            }
        }
        
        $this->info("Total notifikasi terkirim: {$totalNotified}");
        
        // Kirim ringkasan mingguan (jika diminta)
        if ($sendSummary) {
            $this->sendWeeklySummary();
        }
        
        $this->info('=========================================');
        $this->info('Pengecekan piutang selesai!');
        $this->info('=========================================');
        
        return Command::SUCCESS;
    }
    
    protected function sendWeeklySummary()
    {
        $this->info('Mengirim ringkasan piutang mingguan...');
        
        $receivables = Receivable::where('status', '!=', 'paid')
            ->where('due_date', '<=', now()->addDays(7))
            ->orderBy('due_date', 'asc')
            ->get();
        
        $summary = [
            'total_receivable' => Receivable::where('status', '!=', 'paid')->sum('remaining_debt'),
            'overdue_amount' => Receivable::where('due_date', '<', now())
                ->where('status', '!=', 'paid')
                ->sum('remaining_debt'),
            'total_customers' => Receivable::where('status', '!=', 'paid')->distinct('customer_name')->count('customer_name'),
            'overdue_count' => Receivable::where('due_date', '<', now())
                ->where('status', '!=', 'paid')
                ->count(),
        ];
        
        $this->whatsappService->sendReceivableSummary($receivables, $summary);
        
        $this->logger->info('Ringkasan piutang mingguan dikirim ke Admin');
    }
}