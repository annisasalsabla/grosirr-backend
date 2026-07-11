<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AdminNotification;
use App\Models\Payable;
use App\Models\Receivable;
use App\Models\Product;
use App\Models\FcmToken;
use App\Services\FirebasePushService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CheckAdminNotifications extends Command
{
    protected $signature = 'app:check-admin-notifications';

    protected $description = 'Cek hutang, piutang, dan stok menipis, lalu kirim notifikasi ke admin';

    protected FirebasePushService $firebasePush;

    public function __construct(FirebasePushService $firebasePush)
    {
        parent::__construct();
        $this->firebasePush = $firebasePush;
    }

    public function handle(): int
    {
        $this->info('=========================================');
        $this->info('Cek notifikasi admin...');
        $this->info('=========================================');

        // 1. Auto-Resolve issues
        $this->info('Menjalankan auto-resolve...');
        $this->autoResolve();

        $touchedNotifications = [];

        // 2. A) Cek Hutang Supplier
        $this->info('Cek hutang supplier...');
        $touchedNotifications = array_merge(
            $touchedNotifications,
            $this->checkPayablesDue()
        );

        // 3. B) Cek Piutang Pelanggan
        $this->info('Cek piutang pelanggan...');
        $touchedNotifications = array_merge(
            $touchedNotifications,
            $this->checkReceivablesDue()
        );

        // 4. C) Cek Stok Menipis/Habis
        $this->info('Cek stok menipis...');
        $touchedNotifications = array_merge(
            $touchedNotifications,
            $this->checkLowStock()
        );

        // 5. D) Kirim Push Notification untuk semua notifikasi yang di-insert/update hari ini
        $this->info('Total notifikasi (baru/diupdate): ' . count($touchedNotifications));

        if (count($touchedNotifications) > 0) {
            $this->sendPushNotification($touchedNotifications);
        } else {
            $this->info('Tidak ada notifikasi aktif untuk di-push hari ini.');
        }

        $this->info('=========================================');
        $this->info('Selesai!');
        $this->info('=========================================');

        return Command::SUCCESS;
    }

    protected function autoResolve(): void
    {
        $activeNotifications = AdminNotification::active()->get();

        foreach ($activeNotifications as $notif) {
            $resolved = false;

            if ($notif->type === 'piutang_jatuh_tempo') {
                $receivable = Receivable::find($notif->reference_id);
                if (!$receivable || $receivable->status === 'paid' || $receivable->remaining_debt <= 0) {
                    $resolved = true;
                }
            } elseif ($notif->type === 'hutang_jatuh_tempo') {
                $payable = Payable::find($notif->reference_id);
                if (!$payable || $payable->status === 'paid' || $payable->remaining_debt <= 0) {
                    $resolved = true;
                }
            } elseif (in_array($notif->type, ['stok_menipis', 'stok_habis'])) {
                $product = Product::find($notif->reference_id);
                if (!$product || ($product->stock > $product->min_stock && $product->stock > 0)) {
                    $resolved = true;
                }
            }

            if ($resolved) {
                $notif->update(['status' => 'resolved']);
                $this->info("Notifikasi ID {$notif->id} ({$notif->type}) ditandai selesai.");
            }
        }
    }

    protected function getOverdueMessage(string $dueDateStr): string
    {
        $dueDate = Carbon::parse($dueDateStr)->startOfDay();
        $today = now()->startOfDay();

        if ($dueDate->equalTo($today)) {
            return "Jatuh tempo hari ini";
        } elseif ($dueDate->lessThan($today)) {
            $days = $dueDate->diffInDays($today);
            return "Sudah melebihi {$days} hari tenggat";
        } else {
            $days = $today->diffInDays($dueDate);
            return "Akan jatuh tempo dalam {$days} hari";
        }
    }

    protected function checkPayablesDue(): array
    {
        $notifications = [];
        $threeDaysFromNow = now()->addDays(3)->toDateString();
        $today = now()->toDateString();

        $payables = Payable::with('supplier')
            ->where('status', '!=', 'paid')
            ->where('remaining_debt', '>', 0)
            ->where(function ($q) use ($threeDaysFromNow, $today) {
                $q->whereBetween('due_date', [$today, $threeDaysFromNow])
                    ->orWhere('due_date', '<', $today);
            })
            ->get();

        foreach ($payables as $payable) {
            $overdueMsg = $this->getOverdueMessage($payable->due_date);
            $dueDateStr = Carbon::parse($payable->due_date)->format('d M Y');
            
            $title = 'Hutang Jatuh Tempo';
            $message = sprintf(
                'Hutang ke %s sebesar Rp%s. %s (%s)',
                $payable->supplier->name,
                number_format($payable->remaining_debt, 0, ',', '.'),
                $overdueMsg,
                $dueDateStr
            );

            $notif = AdminNotification::active()
                ->where('reference_type', 'payable')
                ->where('reference_id', $payable->id)
                ->where('type', 'hutang_jatuh_tempo')
                ->first();

            if ($notif) {
                if ($notif->updated_at->diffInHours(now()) >= 24) {
                    $notif->update([
                        'is_read' => false,
                        'message' => $message,
                        'updated_at' => now(),
                    ]);
                    $this->info("  -> Update notifikasi hutang: {$notif->title}");
                    $notifications[] = $notif;
                } else {
                    $this->info("  -> Skip update (Dedup 24 jam): {$notif->title}");
                }
            } else {
                $notif = AdminNotification::create([
                    'type' => 'hutang_jatuh_tempo',
                    'title' => $title,
                    'message' => $message,
                    'reference_id' => $payable->id,
                    'reference_type' => 'payable',
                    'status' => 'active',
                ]);
                $this->info("  -> Buat notifikasi hutang baru: {$notif->title}");
                $notifications[] = $notif;
            }
        }

        return $notifications;
    }

    protected function checkReceivablesDue(): array
    {
        $notifications = [];
        $threeDaysFromNow = now()->addDays(3)->toDateString();
        $today = now()->toDateString();

        $receivables = Receivable::where('status', '!=', 'paid')
            ->where('remaining_debt', '>', 0)
            ->where(function ($q) use ($threeDaysFromNow, $today) {
                $q->whereBetween('due_date', [$today, $threeDaysFromNow])
                    ->orWhere('due_date', '<', $today);
            })
            ->get();

        foreach ($receivables as $receivable) {
            $overdueMsg = $this->getOverdueMessage($receivable->due_date);
            $dueDateStr = Carbon::parse($receivable->due_date)->format('d M Y');

            $title = 'Piutang Jatuh Tempo';
            $message = sprintf(
                'Piutang dari %s sebesar Rp%s. %s (%s)',
                $receivable->customer_name,
                number_format($receivable->remaining_debt, 0, ',', '.'),
                $overdueMsg,
                $dueDateStr
            );

            $notif = AdminNotification::active()
                ->where('reference_type', 'receivable')
                ->where('reference_id', $receivable->id)
                ->where('type', 'piutang_jatuh_tempo')
                ->first();

            if ($notif) {
                if ($notif->updated_at->diffInHours(now()) >= 24) {
                    $notif->update([
                        'is_read' => false,
                        'message' => $message,
                        'updated_at' => now(),
                    ]);
                    $this->info("  -> Update notifikasi piutang: {$notif->title}");
                    $notifications[] = $notif;
                } else {
                    $this->info("  -> Skip update (Dedup 24 jam): {$notif->title}");
                }
            } else {
                $notif = AdminNotification::create([
                    'type' => 'piutang_jatuh_tempo',
                    'title' => $title,
                    'message' => $message,
                    'reference_id' => $receivable->id,
                    'reference_type' => 'receivable',
                    'status' => 'active',
                ]);
                $this->info("  -> Buat notifikasi piutang baru: {$notif->title}");
                $notifications[] = $notif;
            }
        }

        return $notifications;
    }

    protected function checkLowStock(): array
    {
        $notifications = [];

        $products = Product::whereColumn('stock', '<=', 'min_stock')
            ->get();

        foreach ($products as $product) {
            // It could be out of stock (0) or just low (>0 but <= min_stock)
            $isOutOfStock = $product->stock <= 0;
            $type = $isOutOfStock ? 'stok_habis' : 'stok_menipis';
            $title = $isOutOfStock ? 'Stok Habis' : 'Stok Menipis';
            
            $message = sprintf(
                'Stok %s %s. Sisa %s %s (Batas minimum: %s)',
                $product->name,
                $isOutOfStock ? 'sudah habis' : 'menipis',
                $product->stock,
                $product->unit,
                $product->min_stock
            );

            // Cari notifikasi stok_menipis atau stok_habis yang aktif untuk produk ini
            $notif = AdminNotification::active()
                ->where('reference_type', 'product')
                ->where('reference_id', $product->id)
                ->whereIn('type', ['stok_menipis', 'stok_habis'])
                ->first();

            if ($notif) {
                // Dedup 24 jam, KECUALI jika statusnya berubah (misal dari menipis jadi habis)
                if ($notif->updated_at->diffInHours(now()) >= 24 || $notif->type !== $type) {
                    $notif->update([
                        'type' => $type,
                        'title' => $title,
                        'is_read' => false,
                        'message' => $message,
                        'updated_at' => now(),
                    ]);
                    $this->info("  -> Update notifikasi stok: {$notif->title}");
                    $notifications[] = $notif;
                } else {
                    $this->info("  -> Skip update (Dedup 24 jam): {$notif->title}");
                }
            } else {
                $notif = AdminNotification::create([
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'reference_id' => $product->id,
                    'reference_type' => 'product',
                    'status' => 'active',
                ]);
                $this->info("  -> Buat notifikasi stok baru: {$notif->title}");
                $notifications[] = $notif;
            }
        }

        return $notifications;
    }

    protected function sendPushNotification(array $notifications): void
    {
        $adminTokens = FcmToken::whereHas('user', function ($q) {
            $q->where('role', 'admin')->where('is_active', true);
        })->pluck('fcm_token')->unique()->values()->toArray();

        if (empty($adminTokens)) {
            $this->warn('Tidak ada FCM token untuk admin.');
            return;
        }

        $this->info('Mengirim push ke ' . count($adminTokens) . ' device admin...');

        $count = count($notifications);
        if ($count === 1) {
            $title = $notifications[0]->title;
            $body = $notifications[0]->message;
        } else {
            $grouped = collect($notifications)->groupBy('type');

            $summaryParts = [];
            if ($grouped->has('hutang_jatuh_tempo')) {
                $summaryParts[] = "{$grouped['hutang_jatuh_tempo']->count()} hutang";
            }
            if ($grouped->has('piutang_jatuh_tempo')) {
                $summaryParts[] = "{$grouped['piutang_jatuh_tempo']->count()} piutang";
            }
            if ($grouped->has('stok_menipis')) {
                $summaryParts[] = "{$grouped['stok_menipis']->count()} stok menipis";
            }
            if ($grouped->has('stok_habis')) {
                $summaryParts[] = "{$grouped['stok_habis']->count()} stok habis";
            }

            $title = "Ada {$count} notifikasi aktif";
            $body = implode(', ', $summaryParts) . ' memerlukan perhatian Anda. Cek sekarang!';
        }

        $data = [
            'type' => 'admin_notification',
            'click_action' => 'OPEN_NOTIFICATIONS',
        ];

        $result = $this->firebasePush->sendToMultipleTokens(
            $adminTokens,
            $title,
            $body,
            $data
        );

        if ($result) {
            $this->info('Push notification berhasil dikirim!');
        } else {
            $this->warn('Push notification gagal dikirim.');
            Log::warning('Failed to send Firebase push notification', [
                'tokens_count' => count($adminTokens),
                'notifications_count' => $count,
            ]);
        }
    }
}