<?php

namespace App\Http\Controllers;

use App\Models\NotificationLog;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use Illuminate\Http\Request;


class NotificationController extends Controller
{
    use ApiResponseTrait;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $type = $request->input('type');
            $status = $request->input('status');
            
            $query = NotificationLog::orderBy('created_at', 'desc');
            
            if ($type) {
                $query->where('type', $type);
            }
            
            if ($status) {
                $query->where('status', $status);
            }
            
            $notifications = $query->paginate($perPage);
            
            $summary = [
                'total_sent' => NotificationLog::where('status', 'sent')->count(),
                'total_failed' => NotificationLog::where('status', 'failed')->count(),
                'last_24h' => NotificationLog::where('created_at', '>=', now()->subDay())->count(),
            ];
            
            return $this->success([
                'notifications' => $notifications,
                'summary' => $summary
            ], 'Riwayat notifikasi berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get notifications error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat riwayat notifikasi', null, 500);
        }
    }

    public function show($id)
    {
        try {
            $notification = NotificationLog::findOrFail($id);
            return $this->success($notification, 'Detail notifikasi berhasil dimuat', 200);
        } catch (\Exception $e) {
            return $this->error('Notifikasi tidak ditemukan', null, 404);
        }
    }

    public function resend($id, Request $request)
    {
        try {
            $notification = NotificationLog::findOrFail($id);
            
            // Logic untuk mengirim ulang notifikasi
            $success = false;
            
            switch ($notification->type) {
                case 'email':
                    // Resend email logic
                    break;
                case 'whatsapp':
                    // Resend WhatsApp logic
                    break;
                case 'telegram':
                    // Resend Telegram logic
                    break;
            }
            
            $this->logger->info('Notifikasi dikirim ulang', [
                'notification_id' => $id,
                'type' => $notification->type,
                'user_id' => $request->user()->id
            ]);
            
            return $this->success(null, 'Notifikasi berhasil dikirim ulang', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Resend notification error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mengirim ulang notifikasi', null, 500);
        }
    }
}